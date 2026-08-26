<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the audit trail (BRD FR-SEC-02, 7.2, section 20).
 *
 * Read-only, and there is no route here that writes, edits or deletes a row. A trail
 * anyone could edit answers no question worth asking, which is also why AuditLog has
 * no updated_at and nothing in the application ever touches an existing entry.
 *
 * The scoping is done here rather than by a global scope, because the two audiences
 * want opposite things. A store owner may read their own store's trail and nothing
 * else. The platform supervisor is entitled to all of it, including the
 * platform-level actions that belong to no merchant — and a scope that filtered by
 * merchant would hide exactly those from the only person who should see them.
 */
class AuditLogController extends Controller
{
    /** A page big enough to scan, small enough to render. */
    private const PER_PAGE = 40;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_id' => ['nullable', 'integer'],
        ]);

        $logs = $this->scoped($request)
            ->with(['user', 'merchant'])
            ->when(
                ($filters['action'] ?? null) !== null,
                // A prefix match, so "merchant." brings the whole family without the
                // reader having to know every action name in it.
                fn (Builder $query) => $query->where('action', 'like', $filters['action'] . '%')
            )
            ->when(
                ($filters['user_id'] ?? null) !== null,
                fn (Builder $query) => $query->where('user_id', $filters['user_id'])
            )
            ->when(
                ($filters['entity_type'] ?? null) !== null,
                fn (Builder $query) => $query->where('entity_type', 'like', '%' . $filters['entity_type'])
            )
            ->when(
                ($filters['entity_id'] ?? null) !== null,
                fn (Builder $query) => $query->where('entity_id', $filters['entity_id'])
            )
            ->when(
                ($filters['from'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from'])
            )
            ->when(
                ($filters['to'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to'])
            )
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'data' => AuditLogResource::collection($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
            ],
        ]);
    }

    /**
     * The action names actually present, for the filter — built from the data rather
     * than a hard-coded list, so a new audited action appears in the filter the first
     * time it happens instead of when someone remembers to add it here.
     */
    public function actions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->scoped($request)
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }

    /**
     * Two shapes of the same trail, for the panel above the list.
     *
     * A page of forty entries answers "what happened"; it cannot answer "what is
     * this store's activity made of" or "when does the work happen". Both are one
     * grouped query each, and both respect the filters already on screen — a chart
     * that ignores the filter beside it is a chart that lies.
     */
    public function stats(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $base = fn () => $this->scoped($request)
            ->when(
                ($filters['action'] ?? null) !== null,
                fn (Builder $query) => $query->where('action', 'like', $filters['action'] . '%')
            )
            ->when(
                ($filters['from'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from'])
            )
            ->when(
                ($filters['to'] ?? null) !== null,
                fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to'])
            );

        /*
         * The five commonest actions, and everything else folded into one bucket. A
         * chart with twenty slices communicates nothing, and the tail of an audit
         * trail is long by nature.
         */
        $byAction = $base()
            ->groupBy('action')
            ->select(['action', DB::raw('COUNT(*) AS total')])
            ->orderByDesc('total')
            ->get();

        $top = $byAction->take(5)->map(fn ($row) => [
            'action' => $row->action,
            'total' => (int) $row->total,
        ])->values();

        $rest = (int) $byAction->skip(5)->sum('total');

        // Twenty-four buckets, including the empty ones: a quiet hour is part of the
        // shape, and a chart that skips it draws a working day that never existed.
        $counts = $base()
            ->groupBy(DB::raw(SqlDialect::hour('created_at')))
            ->select([
                DB::raw(SqlDialect::hour('created_at') . ' AS hour'),
                DB::raw('COUNT(*) AS total'),
            ])
            ->pluck('total', 'hour');

        $byHour = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $byHour[] = ['hour' => $hour, 'total' => (int) ($counts[$hour] ?? 0)];
        }

        return response()->json([
            'total' => $base()->count(),
            'by_action' => $top,
            'other_total' => $rest,
            'by_hour' => $byHour,
        ]);
    }

    private function scoped(Request $request): Builder
    {
        $user = $request->user();

        return AuditLog::query()->when(
            ! $user->role->isPlatformAdmin(),
            fn (Builder $query) => $query->where('merchant_id', $user->merchant_id)
        );
    }
}
