<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exporting the customer base (BRD 7.2, customers.export, BR-019).
 *
 * The customer list is the merchant's most valuable asset and the easiest thing for
 * a departing employee to walk out with, so the cases here are about the narrowness
 * of the path: who may use it, what it leaves out, and the fact that using it is
 * recorded. The file itself also has to survive Excel on a shop laptop, which is
 * why the bytes are asserted rather than just the status code.
 */
class CustomerExportTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $owner;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->owner = User::factory()->owner($this->merchant->id)->create();
        $this->manager = User::factory()->branchManager($this->branch)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return app(TenantContext::class)->for($this->merchant->id, fn () => Customer::create([
            'phone' => '099' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'name' => 'Customer',
            'registered_at_branch_id' => $this->branch->id,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function export(User $user, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->get('/api/v1/customers/export?' . http_build_query($query));
    }

    private function csv(\Illuminate\Testing\TestResponse $response): string
    {
        return $response->streamedContent();
    }

    // -----------------------------------------------------------------
    // Who may export (BR-019)
    // -----------------------------------------------------------------

    public function test_only_the_owner_can_export(): void
    {
        $this->customer();

        // BR-019 names the sales rep, and a branch manager has no such row either.
        $this->export($this->rep)->assertForbidden();
        $this->export($this->manager)->assertForbidden();

        $this->export($this->owner)->assertOk();
    }

    public function test_every_export_is_recorded_with_its_row_count(): void
    {
        $this->customer();
        $this->customer();

        $this->export($this->owner)->assertOk();

        // Section 16: an export is a disclosure of personal data, and a disclosure
        // nobody recorded cannot be answered for later.
        $log = AuditLog::withoutGlobalScopes()->where('action', 'customers.exported')->sole();
        $this->assertSame(2, $log->after['rows']);
        $this->assertSame($this->owner->id, $log->user_id);
    }

    // -----------------------------------------------------------------
    // The file
    // -----------------------------------------------------------------

    public function test_the_file_carries_the_numbers_the_owner_came_for(): void
    {
        $customer = $this->customer([
            'phone' => '0991234567',
            'name' => 'Sami',
            'consent_status' => ConsentStatus::Granted,
            'last_purchase_at' => now()->subDays(3),
        ]);

        Invoice::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->rep->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-1',
            'amount' => 600,
            'invoice_date' => now()->toDateString(),
        ]);

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => 1,
            'type' => LedgerEntryType::Accrual,
            'amount' => 600,
            'invoice_count_delta' => 1,
        ]);

        $csv = $this->csv($this->export($this->owner)->assertOk());

        $this->assertStringContainsString(
            'phone,name,consent_status,registered_at,last_purchase_at,cycle_number,balance,invoice_count,total_spend,redemption_count',
            $csv
        );
        $this->assertStringContainsString('0991234567,Sami,granted', $csv);
        // The balance comes from the ledger, not a stored column (BR-008).
        $this->assertStringContainsString('600.00,1,600.00,0', $csv);
    }

    public function test_the_file_opens_in_excel_with_arabic_intact(): void
    {
        $this->customer(['name' => 'سامي الحلبي']);

        $csv = $this->csv($this->export($this->owner)->assertOk());

        /*
         * Without the byte order mark Excel reads UTF-8 Arabic as Latin-1 and the
         * owner opens a spreadsheet of mojibake — a file that contains exactly the
         * right bytes and is useless to the person who asked for it.
         */
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('سامي الحلبي', $csv);
    }

    public function test_a_name_that_looks_like_a_formula_is_neutralised(): void
    {
        // Entered by a rep at a till, which is exactly the untrusted input a
        // spreadsheet would happily execute on the owner's laptop.
        $this->customer(['name' => '=cmd|\' /c calc\'!A1']);

        $csv = $this->csv($this->export($this->owner)->assertOk());

        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    public function test_it_is_served_as_a_download_rather_than_shown(): void
    {
        $this->customer();

        $response = $this->export($this->owner)->assertOk();

        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename=customers-' . now()->format('Y-m-d') . '.csv',
            $response->headers->get('content-disposition')
        );
    }

    // -----------------------------------------------------------------
    // What it leaves out
    // -----------------------------------------------------------------

    public function test_an_anonymised_customer_is_never_exported(): void
    {
        $this->customer(['name' => 'Still Here']);
        $this->customer(['name' => 'Asked To Be Forgotten', 'anonymized_at' => now()]);

        $csv = $this->csv($this->export($this->owner)->assertOk());

        // Section 16: their record survives for the accounting trail, but it stopped
        // being personal data the moment it was anonymised.
        $this->assertStringContainsString('Still Here', $csv);
        $this->assertStringNotContainsString('Asked To Be Forgotten', $csv);
    }

    public function test_the_owner_can_ask_for_only_the_customers_who_agreed(): void
    {
        $this->customer(['name' => 'Agreed', 'consent_status' => ConsentStatus::Granted]);
        $this->customer(['name' => 'Withdrew', 'consent_status' => ConsentStatus::Withdrawn]);
        $this->customer(['name' => 'Never Asked']);

        $all = $this->csv($this->export($this->owner)->assertOk());
        $this->assertStringContainsString('Withdrew', $all);

        $consented = $this->csv(
            $this->export($this->owner, ['only_consented' => 1])->assertOk()
        );

        // A campaign goes to the customers who agreed to be contacted, not to
        // everyone the shop ever served (section 16).
        $this->assertStringContainsString('Agreed', $consented);
        $this->assertStringNotContainsString('Withdrew', $consented);
        $this->assertStringNotContainsString('Never Asked', $consented);
    }

    public function test_it_can_be_narrowed_by_branch_and_by_registration_date(): void
    {
        $otherBranch = Branch::factory()->for($this->merchant)->create();

        $this->customer(['name' => 'Damascus Customer']);
        $this->customer(['name' => 'Aleppo Customer', 'registered_at_branch_id' => $otherBranch->id]);

        $old = $this->customer(['name' => 'Old Customer']);
        $old->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();

        $byBranch = $this->csv(
            $this->export($this->owner, ['branch_id' => $otherBranch->id])->assertOk()
        );
        $this->assertStringContainsString('Aleppo Customer', $byBranch);
        $this->assertStringNotContainsString('Damascus Customer', $byBranch);

        $recent = $this->csv(
            $this->export($this->owner, ['from' => now()->startOfMonth()->toDateString()])->assertOk()
        );
        $this->assertStringContainsString('Damascus Customer', $recent);
        $this->assertStringNotContainsString('Old Customer', $recent);
    }

    public function test_no_export_ever_contains_another_stores_customers(): void
    {
        $this->customer(['name' => 'My Customer']);

        $other = Merchant::factory()->create();

        app(TenantContext::class)->for($other->id, fn () => Customer::create([
            'phone' => '0980000000',
            'name' => 'Their Customer',
        ]));

        $csv = $this->csv($this->export($this->owner)->assertOk());

        $this->assertStringContainsString('My Customer', $csv);
        $this->assertStringNotContainsString('Their Customer', $csv);

        $log = AuditLog::withoutGlobalScopes()->where('action', 'customers.exported')->sole();
        $this->assertSame(1, $log->after['rows']);
    }

    public function test_an_empty_list_still_produces_a_readable_file(): void
    {
        $csv = $this->csv($this->export($this->owner)->assertOk());

        // A header and nothing else, rather than an empty file the owner has to
        // guess about.
        $this->assertStringContainsString('phone,name,consent_status', $csv);
        $this->assertSame(1, substr_count(trim($csv), "\n") + 1);
    }
}
