<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DayBalance;
use App\Models\User;
use App\Services\Pos\DayBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DayBalanceController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /** A drawer count is a plain PKR figure; the cash ledger caps at the same. */
    private const MAX_AMOUNT = 999999999.99;

    public function __construct(private DayBookService $dayBook) {}

    /** Past days, newest first — opening, closing, expected and the variance. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DayBalance::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $paginator = DayBalance::query()
            ->where('user_id', $request->user()->dataOwnerId())
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        $days = collect($paginator->items());
        $names = $this->staffNames($days);

        return response()->json([
            'days' => $days->map(fn (DayBalance $day) => $this->payload($day, $names)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * What the till loads with: whether today has been opened, and — if it has
     * — what the drawer should hold if nobody has miscounted yet.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DayBalance::class);

        $user = $request->user();
        $date = $this->dayBook->businessDate();
        $day = $this->dayBook->findForDate($user, $date);
        $cash = $this->dayBook->cashPosition($user, $date);
        $opening = $day ? (float) $day->opening_amount : 0.0;

        return response()->json([
            'date' => $date,
            'is_open' => (bool) $day?->isOpen(),
            'is_closed' => $day !== null && ! $day->isOpen(),
            'day_book' => $day ? $this->payload($day, $this->staffNames(collect([$day]))) : null,
            'opening_amount' => $opening,
            // Live, not frozen: the stored `expected_amount` is only written at
            // closing time. Until then this is what the drawer should hold.
            'expected_cash' => round($opening + $cash['net'], 2),
            'cash_takings' => $cash['in'],
            'cash_refunds' => $cash['refunds'],
            'sales_total' => $cash['sales_total'],
            'sales_count' => $cash['sales_count'],
        ]);
    }

    /** Record the opening float. Safe to call again — see DayBookService. */
    public function open(Request $request): JsonResponse
    {
        $this->authorize('create', DayBalance::class);

        $validated = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
        ]);

        $day = $this->dayBook->open($request->user(), (float) $validated['opening_amount']);

        return response()->json([
            'message' => $day->wasRecentlyCreated
                ? 'Day book opened.'
                : 'The day book was already open.',
            'already_open' => ! $day->wasRecentlyCreated,
            'day_book' => $this->payload($day, $this->staffNames(collect([$day]))),
        ], $day->wasRecentlyCreated ? 201 : 200);
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
        ]);

        $user = $request->user();
        $existing = $this->dayBook->findForDate($user, $this->dayBook->businessDate());

        // Nothing to authorize when the till was never opened — the service
        // answers that with a validation error the cashier can act on.
        if ($existing) {
            $this->authorize('close', $existing);
        }

        $day = $this->dayBook->close($user, (float) $validated['closing_amount']);

        return response()->json([
            'message' => 'Day book closed.',
            'day_book' => $this->payload($day, $this->staffNames(collect([$day]))),
        ]);
    }

    /**
     * @param  Collection<int, DayBalance>  $days
     * @return array<int, string>
     */
    private function staffNames(Collection $days): array
    {
        $ids = $days->flatMap(fn (DayBalance $day) => [$day->opened_by, $day->closed_by])
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function payload(DayBalance $day, array $names = []): array
    {
        return [
            'id' => $day->id,
            'business_date' => $day->business_date?->format('Y-m-d'),
            'opening_amount' => (float) $day->opening_amount,
            'closing_amount' => $day->closing_amount === null ? null : (float) $day->closing_amount,
            'expected_amount' => $day->expected_amount === null ? null : (float) $day->expected_amount,
            'variance' => $day->variance(),
            'is_open' => $day->isOpen(),
            'opened_at' => $day->opened_at?->toIso8601String(),
            'closed_at' => $day->closed_at?->toIso8601String(),
            'opened_by' => $day->opened_by,
            'opened_by_name' => $names[$day->opened_by] ?? null,
            'closed_by' => $day->closed_by,
            'closed_by_name' => $names[$day->closed_by] ?? null,
            'opening_entry_id' => $day->opening_entry_id,
            'closing_entry_id' => $day->closing_entry_id,
        ];
    }
}
