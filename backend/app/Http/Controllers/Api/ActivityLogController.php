<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(private ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::in(ActivityLogger::ACTIONS)],
            'subject_type' => ['nullable', Rule::in(ActivityLogger::SUBJECT_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->scoped($request->user());

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['subject_type'])) {
            $query->where('subject_type', $validated['subject_type']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        // Newest first, tie-broken on id: a burst of edits inside one second
        // would otherwise come back in an arbitrary order and read as nonsense.
        $query->orderByDesc('created_at')->orderByDesc('id');

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'activity' => collect($paginator->items())->map(fn (ActivityLog $log) => $this->payload($log)),
            // Built from the unfiltered scope so picking a person does not empty
            // the dropdown you picked them from.
            'actors' => $this->actors($request->user()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * What this account is allowed to read.
     *
     * A shop admin (and any staff member trusted with the catalogue) sees the
     * whole shop. A till-only staff account sees just its own actions — the
     * trail carries cost prices, which is exactly the number a shop owner does
     * not hand to every cashier. Either way the shop id pins the rows, so no
     * account can reach another shop's history.
     *
     * @return Builder<ActivityLog>
     */
    private function scoped(User $user): Builder
    {
        $query = ActivityLog::query();
        $shopId = $this->activity->shopIdFor($user);

        if ($shopId === null) {
            // A shop admin who has not created a shop yet: their own rows are
            // the only ones that exist for them, and whereNull keeps them from
            // seeing anybody else's shop-less rows.
            return $query->whereNull('shop_id')->where('user_id', $user->id);
        }

        $query->where('shop_id', $shopId);

        if (! $user->canManageCatalogue()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    /**
     * Distinct actors inside the caller's scope, for the report's filter.
     *
     * @return array<int, array{id: int|null, name: string|null}>
     */
    private function actors(User $user): array
    {
        return $this->scoped($user)
            ->select('user_id', 'user_name')
            ->whereNotNull('user_id')
            ->distinct()
            ->orderBy('user_name')
            ->get()
            ->map(fn (ActivityLog $log) => ['id' => $log->user_id, 'name' => $log->user_name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            // Captured at write time, so a deleted account still has a name here.
            'user_name' => $log->user_name,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            // {field: {from, to}} for an update, {field: value} for a snapshot.
            'changes' => $log->changes ?? [],
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
