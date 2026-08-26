<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\FraudSignalService;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The reports of BRD 9 (RPT-01 to RPT-05).
 *
 * All five sit behind reports.view_own_branch, which by BRD 7.2 both the owner and a
 * branch manager hold. What separates them is not the gate but the period: a
 * branch-bound user's branch is taken from their account, so a manager asking for
 * another branch is answered with their own (see ReportPeriod).
 *
 * Every response carries the period back. A number without the window it was
 * measured over is not a fact, and a screen that lets the dates drift out of step
 * with the figures is worse than no report.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly FraudSignalService $signals,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->reports->summary($period),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->reports->customers($period, $request->user()),
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->reports->branches($period),
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->reports->rewards($period),
        ]);
    }

    public function staff(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->reports->staff($period),
        ]);
    }

    /**
     * The anti-fraud signals of BRD 12.
     *
     * Signals, never verdicts: each one has an innocent explanation, so nothing is
     * blocked and nobody is accused — the finding arrives with the evidence that
     * produced it and the owner decides what it means.
     *
     * Gated on reports.view_all_branches, which BRD 7.2 gives the owner alone. A
     * branch manager must not read it: they are among the people it examines.
     */
    public function signals(Request $request): JsonResponse
    {
        $period = $this->period($request);

        return response()->json([
            'period' => $period->toArray(),
            'data' => $this->signals->detect($period),
        ]);
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromRequest($request, $request->user());
    }
}
