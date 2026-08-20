<?php

namespace App\Services\Reports;

use App\Enums\CorrectionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\VoucherStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\Redemption;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reports of BRD 9 (RPT-01 to RPT-05).
 *
 * Everything is aggregated in the database rather than in PHP. A store with two
 * years of invoices would otherwise load them all to add up one column, and BRD
 * NFR-02 allows five seconds for a report.
 *
 * Two rules run through every query here. Cancelled invoices are excluded from
 * sales, because a cancelled sale is not a sale and leaving it in would make the
 * discount cost look cheaper than it is (BR-010). And the tenant scope does the
 * merchant filtering, so no query here mentions merchant_id — the same reason the
 * rest of the system does not.
 */
class ReportService
{
    /**
     * RPT-01 — the numbers the owner opens the app to see.
     *
     * The cost ratio is the one figure that answers "is this programme worth
     * running": discounts paid as a share of the sales that earned them. BRD 3
     * states the goal as raising repeat purchases, and this is where it shows.
     *
     * @return array<string, mixed>
     */
    public function summary(ReportPeriod $period): array
    {
        $sales = $this->invoiceQuery($period)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count, COUNT(DISTINCT customer_id) AS customers')
            ->first();

        $salesTotal = (float) $sales->total;

        $rewards = $this->redemptionQuery($period)
            ->selectRaw('COALESCE(SUM(discount_amount), 0) AS total, COUNT(*) AS count')
            ->first();

        $discountTotal = (float) $rewards->total;

        $newCustomers = Customer::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->where('registered_at_branch_id', $period->branchId)
            )
            ->count();

        $returned = $this->correctionQuery($period)
            ->selectRaw('COUNT(*) AS count')
            ->first();

        return [
            'sales_total' => $this->money($salesTotal),
            'invoice_count' => (int) $sales->count,
            'average_invoice' => $this->money($sales->count > 0 ? $salesTotal / $sales->count : 0),
            'customers_served' => (int) $sales->customers,
            'new_customers' => $newCustomers,

            'redemption_count' => (int) $rewards->count,
            'discount_total' => $this->money($discountTotal),
            /*
             * Rounded to two decimals as a percentage: 4.31 means the programme
             * gave back 4.31% of what it took in over the window.
             */
            'discount_ratio' => $salesTotal > 0 ? round($discountTotal / $salesTotal * 100, 2) : 0.0,

            'corrections_applied' => (int) $returned->count,
        ];
    }

    /**
     * RPT-02 — the customer side. Three questions, one report: who is spending,
     * who is one purchase away from a reward, and who has stopped coming.
     *
     * @return array<string, mixed>
     */
    public function customers(ReportPeriod $period, User $viewer): array
    {
        $top = $this->invoiceQuery($period)
            ->whereNotNull('customer_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->groupBy('invoices.customer_id', 'customers.name', 'customers.phone')
            ->select([
                'invoices.customer_id',
                'customers.name',
                'customers.phone',
                DB::raw('COALESCE(SUM(invoices.amount), 0) AS total'),
                DB::raw('COUNT(*) AS invoice_count'),
            ])
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'name' => $row->name,
                // BRD BR-019 guards the customer base as the merchant's asset. A
                // full number is shown only to someone who could export it anyway.
                'phone' => $this->phone($row->phone, $viewer),
                'total' => $this->money((float) $row->total),
                'invoice_count' => (int) $row->invoice_count,
            ]);

        /*
         * Inactive by the same measure BR-017 uses for expiry: no purchase for
         * ninety days. It is the list the owner can actually act on with a message.
         */
        $inactiveSince = $period->to->subDays(90);

        $counts = Customer::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN last_purchase_at IS NULL THEN 1 ELSE 0 END) AS never_bought')
            ->selectRaw('SUM(CASE WHEN last_purchase_at < ? THEN 1 ELSE 0 END) AS inactive', [$inactiveSince])
            ->first();

        return [
            'total_customers' => (int) $counts->total,
            'never_bought' => (int) $counts->never_bought,
            'inactive' => (int) $counts->inactive,
            'inactive_since' => $inactiveSince->toDateString(),
            'top_customers' => $top,
        ];
    }

    /**
     * RPT-03 — one row per branch, so the owner can compare them.
     *
     * Every active branch appears, including the ones that sold nothing in the
     * window: a branch with no sales is the row worth looking at, and a query that
     * only sums what exists would hide it.
     *
     * @return list<array<string, mixed>>
     */
    public function branches(ReportPeriod $period): array
    {
        $branches = Branch::query()
            ->when($period->branchId !== null, fn (Builder $q) => $q->whereKey($period->branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $sales = $this->invoiceQuery($period)
            ->groupBy('branch_id')
            ->select([
                'branch_id',
                DB::raw('COALESCE(SUM(amount), 0) AS total'),
                DB::raw('COUNT(*) AS count'),
                DB::raw('COUNT(DISTINCT customer_id) AS customers'),
            ])
            ->get()
            ->keyBy('branch_id');

        $rewards = $this->redemptionQuery($period)
            ->groupBy('branch_id')
            ->select([
                'branch_id',
                DB::raw('COALESCE(SUM(discount_amount), 0) AS total'),
                DB::raw('COUNT(*) AS count'),
            ])
            ->get()
            ->keyBy('branch_id');

        return $branches->map(function (Branch $branch) use ($sales, $rewards) {
            $branchSales = $sales->get($branch->id);
            $branchRewards = $rewards->get($branch->id);

            $total = (float) ($branchSales->total ?? 0);
            $count = (int) ($branchSales->count ?? 0);

            return [
                'branch_id' => $branch->id,
                'branch' => $branch->name,
                'is_active' => $branch->is_active,
                'sales_total' => $this->money($total),
                'invoice_count' => $count,
                'average_invoice' => $this->money($count > 0 ? $total / $count : 0),
                'customers_served' => (int) ($branchSales->customers ?? 0),
                'redemption_count' => (int) ($branchRewards->count ?? 0),
                'discount_total' => $this->money((float) ($branchRewards->total ?? 0)),
            ];
        })->values()->all();
    }

    /**
     * RPT-04 — what the programme actually paid out, and what it still owes.
     *
     * The outstanding voucher total is the number a shop owner needs and no other
     * screen shows: credit already promised, not yet spent, not yet expired. It is a
     * liability sitting in customers' pockets.
     *
     * @return array<string, mixed>
     */
    public function rewards(ReportPeriod $period): array
    {
        $byType = $this->redemptionQuery($period)
            ->groupBy('reward_type')
            ->select([
                'reward_type',
                DB::raw('COUNT(*) AS count'),
                DB::raw('COALESCE(SUM(discount_amount), 0) AS paid'),
                DB::raw('COALESCE(SUM(computed_amount), 0) AS computed'),
            ])
            ->get()
            ->map(fn ($row) => [
                'reward_type' => $row->reward_type instanceof \BackedEnum
                    ? $row->reward_type->value
                    : (string) $row->reward_type,
                'count' => (int) $row->count,
                'paid' => $this->money((float) $row->paid),
                // Above `paid` wherever the cap of BR-021 bit.
                'computed' => $this->money((float) $row->computed),
            ])
            ->values()
            ->all();

        // BR-014: every exception, and it should be a short list.
        $overrides = $this->redemptionQuery($period)
            ->where('is_override', true)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(discount_amount), 0) AS total')
            ->first();

        $issued = Voucher::query()
            ->whereBetween('issued_at', [$period->from, $period->to])
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total')
            ->first();

        $used = Voucher::query()
            ->whereBetween('used_at', [$period->from, $period->to])
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total')
            ->first();

        /*
         * Outstanding is measured now, not over the window: it answers what is owed
         * today. Expiry is derived from the date rather than read from the status,
         * because nothing sweeps the table and a stale status would overstate it.
         */
        $outstanding = Voucher::query()
            ->where('status', VoucherStatus::Issued)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total')
            ->first();

        return [
            'by_type' => $byType,
            'override_count' => (int) $overrides->count,
            'override_total' => $this->money((float) $overrides->total),
            'vouchers' => [
                'issued_count' => (int) $issued->count,
                'issued_total' => $this->money((float) $issued->total),
                'used_count' => (int) $used->count,
                'used_total' => $this->money((float) $used->total),
                'outstanding_count' => (int) $outstanding->count,
                'outstanding_total' => $this->money((float) $outstanding->total),
            ],
        ];
    }

    /**
     * RPT-05 — who entered what.
     *
     * Read as a workload report, and it doubles as the first place an anomaly shows
     * up: a rep whose corrections outnumber everyone else's is the pattern AF-02
     * describes, visible here before any alert is built.
     *
     * @return list<array<string, mixed>>
     */
    public function staff(ReportPeriod $period): array
    {
        $sales = $this->invoiceQuery($period)
            ->join('users', 'users.id', '=', 'invoices.user_id')
            ->groupBy('invoices.user_id', 'users.name', 'users.role')
            ->select([
                'invoices.user_id',
                'users.name',
                'users.role',
                DB::raw('COALESCE(SUM(invoices.amount), 0) AS total'),
                DB::raw('COUNT(*) AS count'),
                DB::raw('COUNT(DISTINCT invoices.customer_id) AS customers'),
            ])
            ->orderByDesc('total')
            ->get();

        // Requests raised, whatever was decided: a refused request is still a
        // signal about the person who raised it.
        $corrections = InvoiceCorrection::query()
            ->whereBetween('invoice_corrections.created_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('branch_id', $period->branchId)
                )
            )
            ->groupBy('requested_by')
            ->select(['requested_by', DB::raw('COUNT(*) AS count')])
            ->get()
            ->keyBy('requested_by');

        return $sales->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'name' => $row->name,
            'role' => $row->role instanceof \BackedEnum ? $row->role->value : (string) $row->role,
            'sales_total' => $this->money((float) $row->total),
            'invoice_count' => (int) $row->count,
            'average_invoice' => $this->money($row->count > 0 ? (float) $row->total / (int) $row->count : 0),
            'customers_served' => (int) $row->customers,
            'correction_count' => (int) ($corrections->get($row->user_id)->count ?? 0),
        ])->values()->all();
    }

    // -----------------------------------------------------------------
    // Shared query shapes
    // -----------------------------------------------------------------

    /**
     * Sales in the window: active invoices only, by invoice date rather than entry
     * date, because a sale belongs to the day it happened (BRD FR-INV-02).
     */
    private function invoiceQuery(ReportPeriod $period): Builder
    {
        /*
         * whereDate, not whereBetween: the column is a date and MySQL hands it back
         * as "2026-08-20 00:00:00", so comparing it against "2026-08-20" as a plain
         * string quietly drops the last day of every window.
         */
        return Invoice::query()
            ->where('invoices.status', InvoiceStatus::Active)
            ->whereDate('invoices.invoice_date', '>=', $period->from->toDateString())
            ->whereDate('invoices.invoice_date', '<=', $period->to->toDateString())
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->where('invoices.branch_id', $period->branchId)
            );
    }

    private function redemptionQuery(ReportPeriod $period): Builder
    {
        return Redemption::query()
            ->whereBetween('redeemed_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->where('branch_id', $period->branchId)
            );
    }

    private function correctionQuery(ReportPeriod $period): Builder
    {
        return InvoiceCorrection::query()
            ->where('status', CorrectionStatus::Approved)
            ->whereBetween('reviewed_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('branch_id', $period->branchId)
                )
            );
    }

    /**
     * Money as a two-decimal string, the same shape every other endpoint returns,
     * so the client never has to decide how to round.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * BRD BR-019: a rep may not take the customer base away, and a report is the
     * easy way to try. Anyone without the export right sees a masked number, which
     * is enough to recognise a customer they already know and not enough to build a
     * list from.
     */
    private function phone(string $phone, User $viewer): string
    {
        if ($viewer->can('customers.export')) {
            return $phone;
        }

        $length = mb_strlen($phone);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return mb_substr($phone, 0, 3) . str_repeat('*', $length - 6) . mb_substr($phone, -3);
    }
}
