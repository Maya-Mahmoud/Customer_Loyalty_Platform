<?php

namespace App\Services;

use App\Enums\ConsentStatus;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Taking the customer base out of the system (BRD 7.2, customers.export).
 *
 * BRD BR-019 is the reason this is one narrow, loud path rather than a button on
 * every screen: the customer list is the merchant's most valuable asset and the
 * easiest thing for a departing employee to walk away with. So the owner alone can
 * export, the export is written to the audit trail with its row count, and every
 * other screen in the system shows one customer at a time.
 *
 * Section 16 makes the same demand from the other direction: an export is a
 * disclosure of personal data, and a disclosure nobody recorded cannot be answered
 * for later.
 */
class CustomerExportService
{
    /**
     * The header row, and the order every data row follows.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'phone',
        'name',
        'consent_status',
        'registered_at',
        'last_purchase_at',
        'cycle_number',
        'balance',
        'invoice_count',
        'total_spend',
        'redemption_count',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array{from?: string|null, to?: string|null, branch_id?: int|null, only_consented?: bool}  $filters
     */
    public function stream(array $filters, User $actor): StreamedResponse
    {
        $query = $this->query($filters);

        // Counted before streaming: the audit entry has to say how many records
        // left, and by the time the response is sent it is too late to add it.
        $count = (clone $query)->toBase()->getCountForPagination();

        $this->audit->record(
            action: 'customers.exported',
            after: [
                'rows' => $count,
                'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== false),
            ],
            actor: $actor,
        );

        $filename = 'customers-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            /*
             * A byte order mark, because the audience for this file is Excel on a
             * shop's laptop. Without it Excel reads UTF-8 Arabic as Latin-1 and the
             * owner opens a spreadsheet of mojibake — a file that technically
             * contains the right bytes and is useless.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, self::COLUMNS);

            // Chunked so a store with fifty thousand customers streams rather than
            // assembling the whole file in memory first.
            $query->chunk(500, function ($customers) use ($handle) {
                foreach ($customers as $customer) {
                    fputcsv($handle, $this->row($customer));
                }

                flush();
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        return Customer::query()
            ->select('customers.*')
            /*
             * The balance of the open cycle, as a subquery rather than a relation
             * loaded per row: a hundred customers would otherwise mean a hundred
             * extra queries, and the ledger is where the number lives (BR-008).
             */
            ->selectSub(
                LedgerEntry::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('ledger_entries.customer_id', 'customers.id')
                    ->whereColumn('ledger_entries.cycle_number', 'customers.current_cycle_number'),
                'balance'
            )
            ->selectSub(
                Invoice::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('invoices.customer_id', 'customers.id')
                    ->where('invoices.status', InvoiceStatus::Active),
                'invoice_count'
            )
            ->selectSub(
                Invoice::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('invoices.customer_id', 'customers.id')
                    ->where('invoices.status', InvoiceStatus::Active),
                'total_spend'
            )
            ->selectSub(
                Redemption::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('redemptions.customer_id', 'customers.id'),
                'redemption_count'
            )
            /*
             * Section 16: a customer who asked to be forgotten is not exported.
             * Their record survives for the accounting trail, but it stops being
             * personal data the moment it is anonymised, and re-exporting it would
             * undo the point of the request.
             */
            ->whereNull('anonymized_at')
            ->when(
                $filters['only_consented'] ?? false,
                fn (Builder $query) => $query->where('consent_status', ConsentStatus::Granted)
            )
            ->when(
                ($filters['branch_id'] ?? null) !== null,
                fn (Builder $query) => $query->where('registered_at_branch_id', $filters['branch_id'])
            )
            ->when(
                ($filters['from'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('customers.created_at', '>=', $filters['from'])
            )
            ->when(
                ($filters['to'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('customers.created_at', '<=', $filters['to'])
            )
            ->orderBy('customers.id');
    }

    /**
     * @return list<string>
     */
    private function row(Customer $customer): array
    {
        return [
            $this->safe($customer->phone),
            $this->safe($customer->name ?? ''),
            $customer->consent_status->value,
            $customer->created_at?->toDateString() ?? '',
            $customer->last_purchase_at?->toDateString() ?? '',
            (string) $customer->current_cycle_number,
            number_format((float) $customer->getAttribute('balance'), 2, '.', ''),
            (string) (int) $customer->getAttribute('invoice_count'),
            number_format((float) $customer->getAttribute('total_spend'), 2, '.', ''),
            (string) (int) $customer->getAttribute('redemption_count'),
        ];
    }

    /**
     * Neutralises a cell that a spreadsheet would treat as a formula.
     *
     * A customer whose name was saved as "=1+1" is a curiosity; one saved as
     * "=cmd|'/c calc'!A1" is an attack that runs on the owner's laptop when they
     * open the file. Prefixing a quote makes it text again, which is what a name is.
     * The values here are entered by staff at a till, so they are exactly the
     * untrusted input this applies to.
     */
    private function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
