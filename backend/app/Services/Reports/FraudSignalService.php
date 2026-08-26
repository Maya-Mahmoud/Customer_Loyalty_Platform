<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\Redemption;
use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The anti-fraud signals of BRD 12.
 *
 * These are signals, not verdicts, and the distinction runs through the whole design.
 * Every pattern here has an innocent explanation — a rep who works the late shift, a
 * customer who genuinely shops every day, a manager covering a branch alone. So
 * nothing is blocked, nobody is accused, and each finding carries the evidence that
 * produced it so the owner can look and decide. BRD 12 asks for detection; acting on
 * it is a conversation between people.
 *
 * They are computed on read rather than stored. A stored alert needs a lifecycle
 * nobody specified — acknowledged, dismissed, reopened — and would freeze the
 * thresholds at the moment it fired. Recomputing means changing a threshold in
 * config immediately changes what the screen says, which is what tuning requires.
 *
 * The thresholds themselves are deliberately forgiving. A screen that lights up
 * during ordinary trading teaches its reader to ignore it, and an ignored alert is
 * worse than no alert at all.
 */
class FraudSignalService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function detect(ReportPeriod $period): array
    {
        $signals = [
            ...$this->outOfHoursEntries($period),
            ...$this->frequentCorrections($period),
            ...$this->backdatedEntries($period),
            ...$this->repeatedRedemptions($period),
            ...$this->repCustomerConcentration($period),
        ];

        /*
         * Severity first, then weight of evidence. An owner with five minutes reads
         * from the top, so the order is part of the feature rather than a detail.
         */
        usort($signals, function (array $a, array $b): int {
            $bySeverity = ($b['severity'] === 'high' ? 1 : 0) <=> ($a['severity'] === 'high' ? 1 : 0);

            return $bySeverity !== 0 ? $bySeverity : $b['count'] <=> $a['count'];
        });

        return $signals;
    }

    /**
     * AF-10: sales keyed in when the shop is shut.
     *
     * The honest explanations are real — a late stocktake, a rep catching up on
     * paperwork — which is why the entry time is reported rather than blocked. What
     * makes it worth a look is that a fabricated invoice has no customer standing
     * there, so it can be written at any hour.
     *
     * @return list<array<string, mixed>>
     */
    private function outOfHoursEntries(ReportPeriod $period): array
    {
        $from = (int) config('clp.fraud.business_hours.from');
        $to = (int) config('clp.fraud.business_hours.to');
        $minimum = (int) config('clp.fraud.out_of_hours_min');

        $rows = $this->invoices($period)
            ->join('users', 'users.id', '=', 'invoices.user_id')
            // The entry time, not the invoice date: the date is typed in, the
            // timestamp is not.
            ->whereRaw(
                sprintf('(%1$s < ? OR %1$s >= ?)', SqlDialect::hour('invoices.created_at')),
                [$from, $to]
            )
            ->groupBy('invoices.user_id', 'users.name')
            ->select([
                'invoices.user_id',
                'users.name',
                DB::raw('COUNT(*) AS entries'),
                DB::raw('COALESCE(SUM(invoices.amount), 0) AS total'),
            ])
            ->having('entries', '>=', $minimum)
            ->orderByDesc('entries')
            ->get();

        return $rows->map(fn ($row) => [
            'code' => 'AF-10',
            'type' => 'out_of_hours',
            'severity' => 'medium',
            'subject' => $row->name,
            'subject_type' => 'user',
            'subject_id' => (int) $row->user_id,
            'count' => (int) $row->entries,
            'amount' => $this->money((float) $row->total),
            'detail' => ['from' => $from, 'to' => $to],
        ])->all();
    }

    /**
     * AF-02: someone whose entries keep needing to be undone.
     *
     * Counted as a share of what they entered as well as a raw number, because the
     * busiest rep will always top a raw count and flagging them for being busy is
     * how a control loses its credibility.
     *
     * @return list<array<string, mixed>>
     */
    private function frequentCorrections(ReportPeriod $period): array
    {
        $minimum = (int) config('clp.fraud.corrections_min');
        $share = (float) config('clp.fraud.corrections_share');

        $corrections = InvoiceCorrection::query()
            ->whereBetween('invoice_corrections.created_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('branch_id', $period->branchId)
                )
            )
            ->join('users', 'users.id', '=', 'invoice_corrections.requested_by')
            ->groupBy('invoice_corrections.requested_by', 'users.name')
            ->select([
                'invoice_corrections.requested_by',
                'users.name',
                DB::raw('COUNT(*) AS requests'),
            ])
            ->having('requests', '>=', $minimum)
            ->get();

        if ($corrections->isEmpty()) {
            return [];
        }

        $entries = $this->invoices($period)
            ->whereIn('invoices.user_id', $corrections->pluck('requested_by'))
            ->groupBy('invoices.user_id')
            ->select(['invoices.user_id', DB::raw('COUNT(*) AS entries')])
            ->pluck('entries', 'invoices.user_id');

        $signals = [];

        foreach ($corrections as $row) {
            $entered = (int) ($entries[$row->requested_by] ?? 0);
            $ratio = $entered > 0 ? (int) $row->requests / $entered : 1.0;

            if ($ratio < $share) {
                continue;
            }

            $signals[] = [
                'code' => 'AF-02',
                'type' => 'frequent_corrections',
                'severity' => 'high',
                'subject' => $row->name,
                'subject_type' => 'user',
                'subject_id' => (int) $row->requested_by,
                'count' => (int) $row->requests,
                'amount' => null,
                'detail' => ['entries' => $entered, 'share' => round($ratio * 100, 1)],
            ];
        }

        return $signals;
    }

    /**
     * Sales entered well after they happened.
     *
     * A day or two is ordinary — the till was busy, the shop was short-staffed. A
     * week is how a sale gets fitted into a cycle that has already been measured, or
     * into a rule that has since been replaced (BR-015).
     *
     * @return list<array<string, mixed>>
     */
    private function backdatedEntries(ReportPeriod $period): array
    {
        $days = (int) config('clp.fraud.backdated_days');
        $minimum = (int) config('clp.fraud.backdated_min');

        $rows = $this->invoices($period)
            ->join('users', 'users.id', '=', 'invoices.user_id')
            ->whereRaw(SqlDialect::daysBetween('invoices.invoice_date', 'invoices.created_at') . ' > ?', [$days])
            ->groupBy('invoices.user_id', 'users.name')
            ->select([
                'invoices.user_id',
                'users.name',
                DB::raw('COUNT(*) AS entries'),
                DB::raw(
                    'MAX(' . SqlDialect::daysBetween('invoices.invoice_date', 'invoices.created_at') . ') AS worst'
                ),
            ])
            ->having('entries', '>=', $minimum)
            ->orderByDesc('entries')
            ->get();

        return $rows->map(fn ($row) => [
            'code' => 'AF-01',
            'type' => 'backdated',
            'severity' => 'medium',
            'subject' => $row->name,
            'subject_type' => 'user',
            'subject_id' => (int) $row->user_id,
            'count' => (int) $row->entries,
            'amount' => null,
            'detail' => ['threshold_days' => $days, 'worst_days' => (int) $row->worst],
        ])->all();
    }

    /**
     * AF-05: the same customer collecting reward after reward.
     *
     * BR-018 already stops two in one day. This looks at the window instead, because
     * a card being farmed comes back every day rather than twice in an afternoon.
     *
     * @return list<array<string, mixed>>
     */
    private function repeatedRedemptions(ReportPeriod $period): array
    {
        $minimum = (int) config('clp.fraud.redemptions_min');

        $rows = Redemption::query()
            ->whereBetween('redeemed_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->where('redemptions.branch_id', $period->branchId)
            )
            ->join('customers', 'customers.id', '=', 'redemptions.customer_id')
            ->groupBy('redemptions.customer_id', 'customers.name', 'customers.phone')
            ->select([
                'redemptions.customer_id',
                'customers.name',
                DB::raw('COUNT(*) AS rewards'),
                DB::raw('COALESCE(SUM(redemptions.discount_amount), 0) AS total'),
                DB::raw('SUM(CASE WHEN redemptions.is_override = 1 THEN 1 ELSE 0 END) AS overrides'),
            ])
            ->having('rewards', '>=', $minimum)
            ->orderByDesc('rewards')
            ->get();

        return $rows->map(fn ($row) => [
            'code' => 'AF-05',
            'type' => 'repeated_redemptions',
            // An exception among them raises it: BR-014 says a person authorised
            // each one by hand.
            'severity' => (int) $row->overrides > 0 ? 'high' : 'medium',
            'subject' => $row->name,
            'subject_type' => 'customer',
            'subject_id' => (int) $row->customer_id,
            'count' => (int) $row->rewards,
            'amount' => $this->money((float) $row->total),
            'detail' => ['overrides' => (int) $row->overrides],
        ])->all();
    }

    /**
     * AF-03 and AF-11: a rep and a customer, alone together.
     *
     * The pattern the BRD describes as collusion: a rep registers a customer, then
     * enters nearly every purchase on that customer's card themselves. Either
     * account alone is unremarkable — a regular has a favourite cashier — but the
     * combination is what a fabricated customer looks like, and it is the reason the
     * customers table records who registered each one.
     *
     * @return list<array<string, mixed>>
     */
    private function repCustomerConcentration(ReportPeriod $period): array
    {
        $share = (float) config('clp.fraud.concentration_share');
        $minimum = (int) config('clp.fraud.concentration_min_invoices');

        $rows = $this->invoices($period)
            ->whereNotNull('invoices.customer_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->join('users', 'users.id', '=', 'customers.registered_by_user_id')
            // Registered by the same person who is keying in the purchases.
            ->whereColumn('customers.registered_by_user_id', 'invoices.user_id')
            ->groupBy('invoices.customer_id', 'customers.name', 'users.name', 'users.id')
            ->select([
                'invoices.customer_id',
                'customers.name AS customer_name',
                'users.id AS user_id',
                'users.name AS user_name',
                DB::raw('COUNT(*) AS own_entries'),
                DB::raw('COALESCE(SUM(invoices.amount), 0) AS total'),
            ])
            ->having('own_entries', '>=', $minimum)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Every entry on those cards, by anybody, so "nearly all of them" can be
        // measured rather than assumed.
        $allEntries = $this->invoices($period)
            ->whereIn('invoices.customer_id', $rows->pluck('customer_id'))
            ->groupBy('invoices.customer_id')
            ->select(['invoices.customer_id', DB::raw('COUNT(*) AS entries')])
            ->pluck('entries', 'invoices.customer_id');

        $signals = [];

        foreach ($rows as $row) {
            $total = (int) ($allEntries[$row->customer_id] ?? $row->own_entries);
            $ratio = $total > 0 ? (int) $row->own_entries / $total : 1.0;

            if ($ratio < $share) {
                continue;
            }

            $signals[] = [
                'code' => 'AF-03',
                'type' => 'rep_customer_concentration',
                'severity' => 'high',
                'subject' => $row->customer_name,
                'subject_type' => 'customer',
                'subject_id' => (int) $row->customer_id,
                'count' => (int) $row->own_entries,
                'amount' => $this->money((float) $row->total),
                'detail' => [
                    'user' => $row->user_name,
                    'user_id' => (int) $row->user_id,
                    'share' => round($ratio * 100, 1),
                ],
            ];
        }

        return $signals;
    }

    /**
     * Active invoices in the window, by entry date rather than invoice date.
     *
     * The detectors are about behaviour, and behaviour happened when the row was
     * written — a back-dated invoice would otherwise fall outside the very window
     * that should be examining it.
     */
    private function invoices(ReportPeriod $period): Builder
    {
        return Invoice::query()
            ->where('invoices.status', InvoiceStatus::Active)
            ->whereBetween('invoices.created_at', [$period->from, $period->to])
            ->when(
                $period->branchId !== null,
                fn (Builder $query) => $query->where('invoices.branch_id', $period->branchId)
            );
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
