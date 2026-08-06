<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Pos\SaleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(private SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['completed', 'refunded', 'partially_refunded'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Sale::query()
            ->withCount('items')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        if (! empty($validated['date'])) {
            $query->whereDate('sold_at', $validated['date']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'sales' => collect($paginator->items())->map(fn (Sale $s) => $this->summary($s)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(Sale::PAYMENT_METHODS)],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'note' => ['nullable', 'string', 'max:500'],

            // Offline till fields. The uuid is minted by the browser before the
            // sale is queued and makes the POST idempotent; sold_at preserves
            // when the sale actually happened rather than when the queue drained.
            'client_uuid' => ['nullable', 'uuid'],
            'offline' => ['nullable', 'boolean'],
            'sold_at' => ['nullable', 'date', 'before_or_equal:now', 'after:'.now()->subDays(30)->toDateTimeString()],
        ]);

        if (filled($validated['client_uuid'] ?? null)) {
            $existing = $this->findByClientUuid($request, $validated['client_uuid']);

            // Replay of a sale we already stored — the till retries whenever a
            // POST times out, and it cannot know whether we got the first one.
            if ($existing) {
                return response()->json(['sale' => $this->payload($existing->load('items'))]);
            }
        }

        try {
            // Stock checks, ownership checks and totals all live in the service
            // so they run inside the same locked transaction as the write.
            $sale = $this->sales->create($request->user(), $validated);
        } catch (UniqueConstraintViolationException) {
            // Two replays of the same queued sale raced past the lookup above.
            // The unique index on (user_id, client_uuid) is the real guarantee;
            // this just turns the loser into the same success the winner got.
            $existing = $this->findByClientUuid($request, $validated['client_uuid'] ?? '');

            if (! $existing) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Could not record the sale. Please try again.',
                ], 409));
            }

            return response()->json(['sale' => $this->payload($existing->load('items'))]);
        }

        return response()->json(['sale' => $this->payload($sale)], 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        return response()->json(['sale' => $this->payload($sale->load('items'))]);
    }

    /**
     * Refund the whole sale, or specific line quantities when `items` is given.
     */
    public function refund(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('refund', $sale);

        $validated = $request->validate([
            'items' => ['nullable', 'array', 'min:1', 'max:200'],
            'items.*.sale_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:9999'],
        ]);

        $refunded = $this->sales->refund($sale, $validated['items'] ?? null);

        return response()->json([
            'message' => $refunded->status === 'refunded'
                ? 'Sale refunded and stock restored.'
                : 'Partial refund recorded and stock restored.',
            'sale' => $this->payload($refunded),
        ]);
    }

    private function findByClientUuid(Request $request, string $uuid): ?Sale
    {
        return Sale::query()
            ->where('user_id', $request->user()->id)
            ->where('client_uuid', $uuid)
            ->first();
    }

    /**
     * Counter summary for the current day — what the cashier reconciles the
     * drawer against at closing.
     */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $sales = Sale::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('sold_at', now()->toDateString())
            ->get();

        // Partially refunded sales still took money, so they count towards the
        // day's takings — net of whatever was handed back.
        $takings = $sales->whereIn('status', ['completed', 'partially_refunded']);

        return response()->json([
            'date' => now()->toDateString(),
            'sales_count' => $sales->where('status', '!=', 'refunded')->count(),
            'refunds_count' => $sales->whereIn('status', ['refunded', 'partially_refunded'])->count(),
            'total' => round((float) $takings->sum(fn (Sale $s) => $s->refundableTotal()), 2),
            'refunded_total' => round((float) $sales->sum('refunded_amount'), 2),
            'by_payment_method' => collect(Sale::PAYMENT_METHODS)
                ->mapWithKeys(fn (string $method) => [
                    $method => round(
                        (float) $takings->where('payment_method', $method)->sum(fn (Sale $s) => $s->refundableTotal()),
                        2
                    ),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'total' => (float) $sale->total,
            'refunded_amount' => (float) $sale->refunded_amount,
            'payment_method' => $sale->payment_method,
            'status' => $sale->status,
            'is_offline' => (bool) $sale->is_offline,
            'items_count' => $sale->items_count ?? $sale->items()->count(),
            'sold_at' => $sale->sold_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'client_uuid' => $sale->client_uuid,
            'is_offline' => (bool) $sale->is_offline,
            'subtotal' => (float) $sale->subtotal,
            'discount_amount' => (float) $sale->discount_amount,
            'total' => (float) $sale->total,
            'refunded_amount' => (float) $sale->refunded_amount,
            'payment_method' => $sale->payment_method,
            'amount_tendered' => $sale->amount_tendered === null ? null : (float) $sale->amount_tendered,
            'change_due' => (float) $sale->change_due,
            'status' => $sale->status,
            'note' => $sale->note,
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'refunded_at' => $sale->refunded_at?->toIso8601String(),
            'items' => $sale->relationLoaded('items')
                ? $sale->items->map(fn (SaleItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'unit_price' => (float) $item->unit_price,
                    'quantity' => $item->quantity,
                    'refunded_quantity' => $item->refunded_quantity,
                    'refundable_quantity' => $item->remainingQuantity(),
                    'line_total' => (float) $item->line_total,
                ])->values()
                : [],
        ];
    }
}
