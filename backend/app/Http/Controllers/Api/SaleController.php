<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\Pos\SalePaymentService;
use App\Services\Pos\SaleService;
use App\Support\SaleProfit;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * The margin expressions live in App\Support\SaleProfit, not here.
     *
     * The sales list and the reports screen both answer "kitna bacha?", and a
     * shop shown two different profits for the same day trusts neither — so the
     * two read the same SQL rather than two copies that drift apart the first
     * time one of them is corrected.
     */
    private const PROFIT_SUM = SaleProfit::PROFIT_SUM;

    private const UNKNOWN_REVENUE_SUM = SaleProfit::UNKNOWN_REVENUE_SUM;

    private const UNKNOWN_LINES_SUM = SaleProfit::UNKNOWN_LINES_SUM;

    public function __construct(
        private SaleService $sales,
        private SalePaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'custom'])],
            'date_from' => ['nullable', 'date', 'required_if:period,custom'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:period,custom'],
            'category_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', Rule::in(Sale::PAYMENT_STATUSES)],
            'status' => ['nullable', Rule::in(['completed', 'refunded', 'partially_refunded'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $ownerId = $request->user()->dataOwnerId();

        $query = Sale::query()
            ->withCount('items')
            ->where('user_id', $ownerId)
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        if (! empty($validated['date'])) {
            $query->whereDate('sold_at', $validated['date']);
        }

        [$from, $to] = $this->resolvePeriod($validated);

        if ($from) {
            $query->whereBetween('sold_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['payment_status'])) {
            $query->where('payment_status', $validated['payment_status']);
        }

        if (! empty($validated['category_id'])) {
            // "Sales containing an item from this category" — an EXISTS rather
            // than a join, so a ticket with three matching lines is still one
            // row in the list. The category is matched through the product, so
            // lines whose product has since been deleted drop out: there is no
            // longer anything to say which category they belonged to.
            $query->whereExists(fn (QueryBuilder $q) => $q->from('sale_items')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->whereColumn('sale_items.sale_id', 'sales.id')
                ->where('products.user_id', $ownerId)
                ->where('products.product_category_id', $validated['category_id']));
        }

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        // One aggregate for the whole page instead of one per row.
        $profits = $this->profitBySale(collect($paginator->items())->pluck('id')->all());

        return response()->json([
            'sales' => collect($paginator->items())
                ->map(fn (Sale $s) => $this->summary($s, $profits[$s->id] ?? $this->emptyProfit())),
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
            // In base units — grams, millilitres or pieces. The till converts
            // "ek pao" to 250 before it posts, so the server never has to guess
            // which unit an unqualified number was meant in. The floor is one
            // thousandth of a base unit: fine enough to divide "Rs 50 of daal"
            // out exactly, coarse enough that zero cannot be sold.
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(Sale::PAYMENT_METHODS)],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'note' => ['nullable', 'string', 'max:500'],

            // Wallet and bank transfers settle at the till but carry a
            // transaction id the shop needs to reconcile against its statement.
            'payment_reference' => ['nullable', 'string', 'max:64'],

            // Credit fields. `paid_amount` is the deposit handed over at the
            // counter; `deposit_method` is how that deposit arrived, since the
            // sale's own payment_method is 'credit' by then.
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'deposit_method' => ['nullable', Rule::in(Sale::SETTLEMENT_METHODS)],
            // Without this the field is stripped by validation before the
            // service can read it, so a deposit taken at the till would record
            // nobody as having received it.
            'received_by_name' => ['nullable', 'string', 'max:120'],

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
                return response()->json(['sale' => $this->payload($existing->load('items', 'payments'))]);
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

            return response()->json(['sale' => $this->payload($existing->load('items', 'payments'))]);
        }

        return response()->json(['sale' => $this->payload($sale)], 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        return response()->json(['sale' => $this->payload($sale->load('items', 'payments'))]);
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
            // Base units again, so half a kilo of the two kilos sold comes back
            // as 500 — a partial refund of a weighed line is otherwise
            // impossible to express.
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        $refunded = $this->sales->refund($sale, $validated['items'] ?? null);

        return response()->json([
            'message' => $refunded->status === 'refunded'
                ? 'Sale refunded and stock restored.'
                : 'Partial refund recorded and stock restored.',
            'sale' => $this->payload($refunded),
        ]);
    }

    /** Record an instalment against a credit sale. */
    public function storePayment(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('settle', $sale);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'max:9999999.99'],
            // 'credit' is missing on purpose: settling a debt with more credit
            // moves no money.
            'method' => ['required', Rule::in(Sale::SETTLEMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
            // Who physically took the money, which is not the same question as
            // `recorded_by`: the son on the counter and the boy who collected at
            // the door both get typed in under whichever login is open.
            'received_by_name' => ['nullable', 'string', 'max:120'],
        ]);

        $settled = $this->payments->settle($request->user(), $sale, $validated);

        return response()->json([
            'message' => $settled->isSettled()
                ? 'Payment recorded. This sale is fully settled.'
                : 'Payment recorded.',
            'sale' => $this->payload($settled),
        ]);
    }

    private function findByClientUuid(Request $request, string $uuid): ?Sale
    {
        return Sale::query()
            ->where('user_id', $request->user()->dataOwnerId())
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
            ->where('user_id', $request->user()->dataOwnerId())
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
            // What is still owed on the day's credit sales — the figure that
            // explains why the drawer is short of the takings total.
            'outstanding_total' => round((float) $takings->sum(fn (Sale $s) => $s->outstandingAmount()), 2),
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
     * Takings and profit for the windows the shop actually reports on. Every
     * figure is a conditional SUM in SQL — a year of sales is answered by two
     * queries rather than by loading the year into memory.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $ownerId = $request->user()->dataOwnerId();
        $windows = $this->statsWindows();
        $totals = $this->totalsByWindow($ownerId, $windows);
        $profits = $this->profitByWindow($ownerId, $windows);

        return response()->json([
            'stats' => collect($windows)->map(fn (array $window, string $key) => [
                'from' => $window[0]->toDateTimeString(),
                'to' => $window[1]->toDateTimeString(),
                'sales_count' => $totals[$key]['count'],
                'sales_total' => $totals[$key]['total'],
                'profit' => $profits[$key],
            ]),
        ]);
    }

    /**
     * The reporting windows, all anchored on "now".
     *
     * The week runs Monday–Sunday explicitly rather than leaning on Carbon's
     * locale-derived first day, so the figure does not move when the app locale
     * changes.
     *
     * @return array<string, array{0: Carbon, 1: Carbon}>
     */
    private function statsWindows(): array
    {
        $now = now();

        return [
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfWeek(Carbon::SUNDAY)],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            // subMonthNoOverflow: on the 31st, plain subMonth() would land in
            // the month before last for the short months.
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        ];
    }

    /**
     * @param  array<string, array{0: Carbon, 1: Carbon}>  $windows
     * @return array<string, array{count: int, total: float}>
     */
    private function totalsByWindow(int $ownerId, array $windows): array
    {
        $selects = [];
        $bindings = [];

        foreach ($windows as $key => [$from, $to]) {
            // Money handed back is not takings. A fully refunded sale nets to
            // zero on its own, so the total needs no status filter — but the
            // count does, or a refunded sale would still look like a sale.
            $selects[] = "SUM(CASE WHEN sold_at BETWEEN ? AND ? THEN total - refunded_amount ELSE 0 END) as {$key}_total";
            $selects[] = "SUM(CASE WHEN sold_at BETWEEN ? AND ? AND status <> 'refunded' THEN 1 ELSE 0 END) as {$key}_count";

            array_push($bindings, ...$this->windowBindings($from, $to), ...$this->windowBindings($from, $to));
        }

        $row = DB::table('sales')
            ->where('user_id', $ownerId)
            ->whereBetween('sold_at', $this->statsSpan($windows))
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $out = [];

        foreach (array_keys($windows) as $key) {
            $out[$key] = [
                'count' => (int) ($row?->{$key.'_count'} ?? 0),
                'total' => round((float) ($row?->{$key.'_total'} ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{0: Carbon, 1: Carbon}>  $windows
     * @return array<string, array{amount: float, is_complete: bool, unknown_cost_revenue: float}>
     */
    private function profitByWindow(int $ownerId, array $windows): array
    {
        $selects = [];
        $bindings = [];

        foreach ($windows as $key => [$from, $to]) {
            foreach ([
                'profit' => self::PROFIT_SUM,
                'unknown_revenue' => self::UNKNOWN_REVENUE_SUM,
                'unknown_lines' => self::UNKNOWN_LINES_SUM,
            ] as $alias => $sum) {
                // Wrap the window test around the existing sums by zeroing the
                // inner expression outside the window.
                $selects[] = str_replace(
                    'CASE WHEN',
                    'CASE WHEN sales.sold_at NOT BETWEEN ? AND ? THEN 0 WHEN',
                    $sum
                )." as {$key}_{$alias}";

                array_push($bindings, ...$this->windowBindings($from, $to));
            }
        }

        $row = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.user_id', $ownerId)
            ->whereBetween('sales.sold_at', $this->statsSpan($windows))
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $out = [];

        foreach (array_keys($windows) as $key) {
            $out[$key] = $this->profitPayload(
                (float) ($row?->{$key.'_profit'} ?? 0),
                (float) ($row?->{$key.'_unknown_revenue'} ?? 0),
                (int) ($row?->{$key.'_unknown_lines'} ?? 0),
            );
        }

        return $out;
    }

    /**
     * Narrowest range covering every window, so the aggregate scans the
     * (user_id, sold_at) index instead of the whole table.
     *
     * @param  array<string, array{0: Carbon, 1: Carbon}>  $windows
     * @return array{0: string, 1: string}
     */
    private function statsSpan(array $windows): array
    {
        $starts = array_map(fn (array $w) => $w[0], $windows);
        $ends = array_map(fn (array $w) => $w[1], $windows);

        return [
            min($starts)->toDateTimeString(),
            max($ends)->toDateTimeString(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function windowBindings(Carbon $from, Carbon $to): array
    {
        return [$from->toDateTimeString(), $to->toDateTimeString()];
    }

    /**
     * Line margin for each of the given sales, keyed by sale id.
     *
     * @param  list<int>  $saleIds
     * @return array<int, array{amount: float, is_complete: bool, unknown_cost_revenue: float}>
     */
    private function profitBySale(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        return DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id')
            ->selectRaw('sale_id, '.self::PROFIT_SUM.' as profit, '
                .self::UNKNOWN_REVENUE_SUM.' as unknown_revenue, '
                .self::UNKNOWN_LINES_SUM.' as unknown_lines')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                (int) $row->sale_id => $this->profitPayload(
                    (float) $row->profit,
                    (float) $row->unknown_revenue,
                    (int) $row->unknown_lines,
                ),
            ])
            ->all();
    }

    /**
     * `is_complete` is the honest bit: false means at least one line had no
     * recorded cost, so `amount` is the margin on the rest and
     * `unknown_cost_revenue` is the takings it says nothing about.
     *
     * @return array{amount: float, is_complete: bool, unknown_cost_revenue: float}
     */
    private function profitPayload(float $amount, float $unknownRevenue, int $unknownLines): array
    {
        return [
            'amount' => round($amount, 2),
            'is_complete' => $unknownLines === 0,
            'unknown_cost_revenue' => round($unknownRevenue, 2),
        ];
    }

    /**
     * @return array{amount: float, is_complete: bool, unknown_cost_revenue: float}
     */
    private function emptyProfit(): array
    {
        return $this->profitPayload(0.0, 0.0, 0);
    }

    /**
     * Resolve the `period` filter to a sold_at range.
     *
     * Week is Monday–Sunday, matching the weekly report and the stats endpoint,
     * so the same sale never lands in two different weeks depending on which
     * screen asked.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolvePeriod(array $validated): array
    {
        $now = now();

        return match ($validated['period'] ?? null) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(Carbon::MONDAY), $now->copy()->endOfWeek(Carbon::SUNDAY)],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'custom' => [
                Carbon::parse($validated['date_from'])->startOfDay(),
                Carbon::parse($validated['date_to'])->endOfDay(),
            ],
            default => [null, null],
        };
    }

    /**
     * @param  array{amount: float, is_complete: bool, unknown_cost_revenue: float}  $profit
     * @return array<string, mixed>
     */
    private function summary(Sale $sale, array $profit): array
    {
        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'total' => (float) $sale->total,
            'refunded_amount' => (float) $sale->refunded_amount,
            'paid_amount' => (float) $sale->paid_amount,
            'outstanding_amount' => $sale->outstandingAmount(),
            'payment_method' => $sale->payment_method,
            'payment_status' => $sale->payment_status,
            'customer_id' => $sale->customer_id,
            'customer_name' => $sale->customer_name,
            'status' => $sale->status,
            'is_offline' => (bool) $sale->is_offline,
            'items_count' => $sale->items_count ?? $sale->items()->count(),
            'profit' => $profit,
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
            'rounding_adjustment' => (float) $sale->rounding_adjustment,
            'total' => (float) $sale->total,
            'refunded_amount' => (float) $sale->refunded_amount,
            'payment_method' => $sale->payment_method,
            'payment_reference' => $sale->payment_reference,
            'payment_status' => $sale->payment_status,
            'paid_amount' => (float) $sale->paid_amount,
            'outstanding_amount' => $sale->outstandingAmount(),
            // The khata page this debt sits on, when the sale went out on
            // credit. The name and phone beside it are the snapshot taken at
            // the till and stay put even if the page is renamed or deleted.
            'customer_id' => $sale->customer_id,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'amount_tendered' => $sale->amount_tendered === null ? null : (float) $sale->amount_tendered,
            'change_due' => (float) $sale->change_due,
            'status' => $sale->status,
            'note' => $sale->note,
            'profit' => $this->profitBySale([$sale->id])[$sale->id] ?? $this->emptyProfit(),
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'refunded_at' => $sale->refunded_at?->toIso8601String(),
            'items' => $sale->relationLoaded('items')
                ? $sale->items->map(fn (SaleItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    // Snapshotted on the line, not read back off the product: a
                    // receipt reprinted next year has to still say "250 g" even
                    // if the shop has since switched to selling by the packet.
                    'unit_type' => $item->unit_type,
                    'base_unit' => $item->base_unit,
                    'price_unit' => $item->price_unit,
                    'unit_price' => (float) $item->unit_price,
                    // Null means the cost was never recorded, which is why the
                    // sale's profit may be flagged incomplete.
                    'unit_cost' => $item->unit_cost === null ? null : (float) $item->unit_cost,
                    'quantity' => (float) $item->quantity,
                    'refunded_quantity' => (float) $item->refunded_quantity,
                    'refundable_quantity' => (float) $item->remainingQuantity(),
                    // The quantity and the price as the counter says them —
                    // "250 g @ Rs 250 / kg" — rather than the per-gram figures
                    // underneath, which no customer would recognise.
                    'quantity_label' => $item->quantityLabel(),
                    'unit_price_label' => $item->unitPriceLabel(),
                    'line_total' => (float) $item->line_total,
                ])->values()
                : [],
            'payments' => $sale->relationLoaded('payments')
                ? $sale->payments->map(fn (SalePayment $payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'note' => $payment->note,
                    'recorded_by' => $payment->recorded_by,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                ])->values()
                : [],
        ];
    }
}
