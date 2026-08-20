<?php

namespace App\Services\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The window and the branch every report is read through.
 *
 * Built from the request and the signed-in user together, because the branch is not
 * the client's to choose: BRD FR-BRN-03 and the matrix of BRD 7.2 give a branch
 * manager their own branch only, and a report is exactly where someone would try to
 * look past it. A branch-bound user's branch is taken from their account and any
 * branch they asked for is ignored, so there is no request that widens their view.
 */
final readonly class ReportPeriod
{
    private const MAX_DAYS = 400;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        /** Null means every branch, which only an owner can reach. */
        public ?int $branchId,
        /** True when the user is confined to one branch by their role. */
        public bool $branchLocked,
    ) {
    }

    public static function fromRequest(Request $request, User $user): self
    {
        $from = self::parse($request->input('from'), 'from')
            ?? CarbonImmutable::now()->startOfMonth();

        $to = self::parse($request->input('to'), 'to')
            ?? CarbonImmutable::now();

        $from = $from->startOfDay();
        $to = $to->endOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'from' => __('The start date has to come before the end date.'),
            ]);
        }

        /*
         * A cap on the window, not on the data. Every report here aggregates in the
         * database, but an unbounded range on a growing table is how a report turns
         * into an outage, and BRD NFR-02 allows five seconds for one.
         */
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'from' => __('Choose a range of a year or less.'),
            ]);
        }

        $locked = $user->role->isBranchBound() && $user->branch_id !== null;

        return new self(
            from: $from,
            to: $to,
            // The requested branch is honoured only for someone who can see them all.
            branchId: $locked ? $user->branch_id : self::parseBranch($request->input('branch_id')),
            branchLocked: $locked,
        );
    }

    private static function parse(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => __('This date is not valid.'),
            ]);
        }
    }

    private static function parseBranch(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branch_id' => $this->branchId,
            'branch_locked' => $this->branchLocked,
            'days' => $this->days(),
        ];
    }
}
