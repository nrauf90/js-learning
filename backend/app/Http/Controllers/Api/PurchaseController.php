<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Pos\PurchaseService;
use App\Support\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(private PurchaseService $purchases) {}

    /* ---------------------------------------------------------- suppliers */

    public function suppliers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        // dataOwnerId(), not id: a staff account books deliveries into its shop
        // owner's catalogue, so scoping to the clerk would show an empty list.
        $suppliers = Supplier::query()
            ->where('user_id', $request->user()->dataOwnerId())
            ->search($validated['search'] ?? null)
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => $this->supplierPayload($s));

        return response()->json(['suppliers' => $suppliers]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $this->authorize('create', Purchase::class);

        $validated = $request->validate($this->supplierRules($request));

        $supplier = Supplier::create([
            ...$validated,
            'user_id' => $request->user()->dataOwnerId(),
        ]);

        return response()->json(['supplier' => $this->supplierPayload($supplier)], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('create', Purchase::class);
        $this->assertOwned($request, $supplier);

        $supplier->update($request->validate($this->supplierRules($request, $supplier)));

        return response()->json(['supplier' => $this->supplierPayload($supplier)]);
    }

    /* ---------------------------------------------------------- purchases */

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'payment_status' => ['nullable', Rule::in(Purchase::PAYMENT_STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Purchase::query()
            ->with('supplier:id,name,phone')
            ->withCount('items')
            ->where('user_id', $request->user()->dataOwnerId())
            ->orderByDesc('purchase_date')
            ->orderByDesc('id');

        if (! empty($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        }

        if (! empty($validated['payment_status'])) {
            $query->where('payment_status', $validated['payment_status']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('purchase_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('purchase_date', '<=', $validated['date_to']);
        }

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'purchases' => collect($paginator->items())->map(fn (Purchase $p) => $this->payload($p)),
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
        $this->authorize('create', Purchase::class);

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            // A date in the future would report goods the shop does not have.
            // The bound is tomorrow rather than today because the app runs in
            // UTC and the shop lives five hours ahead of it: between 7pm and
            // midnight UTC the shopkeeper's own "today" is already the next
            // date, and rejecting it would make the screen unusable each
            // evening.
            'purchase_date' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            // How the deposit was handed over, and by whom. Both describe the
            // first `purchase_payments` row the service writes for it, so the
            // money paid at the door reads the same as every later instalment.
            'deposit_method' => ['nullable', Rule::in(Sale::SETTLEMENT_METHODS)],
            'paid_by_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            // Both quoted in the product's own price unit — "50" against atta
            // priced by the kilo is fifty kilos, and "180" is Rs 180 a kilo.
            // PurchaseService divides them down to grams before anything is
            // stored, so the two figures can never be mixed up in between.
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            // Zero is allowed: a free sack thrown in with an order is real, and
            // it genuinely drags the average cost of that product down.
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        $this->assertSupplierBelongsToUser($request, $validated['supplier_id'] ?? null);

        // Ownership, unit conversion, the weighted average and the stock
        // movements all live in the service so they run inside the same locked
        // transaction as the write.
        $purchase = $this->purchases->create($request->user(), $validated);

        return response()->json(['purchase' => $this->payload($purchase)], 201);
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);

        return response()->json([
            'purchase' => $this->payload($purchase->load('items', 'supplier', 'payments')),
        ]);
    }

    /**
     * What the shop owes its wholesalers — the mirror of the khata's "who owes
     * me", and the question this screen exists to answer at the end of a month.
     *
     * Oldest invoice first: that is the one the supplier will ask about, and it
     * is the order the money would be paid out in anyway.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $purchases = Purchase::query()
            ->with('supplier:id,name,phone')
            ->withCount('items')
            ->where('user_id', $request->user()->dataOwnerId())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get();

        // Grouped by name rather than id so deliveries booked with no supplier
        // still total up under one heading instead of vanishing from the
        // breakdown — the shop owes that money either way.
        $suppliers = $purchases
            ->groupBy(fn (Purchase $p) => $p->supplier?->name ?? 'No supplier')
            ->map(fn ($group, string $name) => [
                'name' => $name,
                'outstanding' => round($group->sum(fn (Purchase $p) => $p->outstandingAmount()), 2),
                'invoices_count' => $group->count(),
            ])
            ->sortByDesc('outstanding')
            ->values();

        return response()->json([
            'purchases' => $purchases->map(fn (Purchase $p) => $this->payload($p))->values(),
            'total_outstanding' => round($purchases->sum(fn (Purchase $p) => $p->outstandingAmount()), 2),
            'invoices_count' => $purchases->count(),
            'suppliers' => $suppliers,
            'oldest_unpaid_date' => $purchases->first()?->purchase_date?->format('Y-m-d'),
            'as_of' => now()->toIso8601String(),
        ]);
    }

    /**
     * Pay something off a supplier's invoice.
     *
     * Two authorisations because they answer different questions: `view` is the
     * shop boundary, and `create` is the catalogue permission that already gates
     * booking the delivery in — settling its bill is the other half of the same
     * job, so the clerk who may not receive stock may not pay for it either.
     */
    public function storePayment(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);
        $this->authorize('create', Purchase::class);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'max:9999999.99'],
            // The till's settlement methods, so both sides of the shop's book
            // name money the same way. 'credit' is absent for the reason it is
            // absent there: paying a bill with more credit moves nothing.
            'method' => ['required', Rule::in(Sale::SETTLEMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'paid_by_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // The lock, the over-payment check and the recomputed total all live in
        // the service so they run inside one transaction.
        $paid = $this->purchases->recordPayment($request->user(), $purchase, $validated);

        return response()->json([
            'message' => $paid->outstandingAmount() <= 0
                ? 'Payment recorded. This invoice is settled in full.'
                : 'Payment recorded.',
            'purchase' => $this->payload($paid),
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * @return array<string, mixed>
     */
    private function supplierRules(Request $request, ?Supplier $supplier = null): array
    {
        return [
            'name' => [
                $supplier ? 'sometimes' : 'required',
                'string',
                'max:160',
                // Two rows for the same wholesaler split one running balance in
                // half, and the shopkeeper has no way to tell which is which.
                Rule::unique('suppliers', 'name')
                    ->where(fn ($q) => $q->where('user_id', $request->user()->dataOwnerId()))
                    ->ignore($supplier?->id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Suppliers have no policy of their own — they exist only to be attached to
     * a purchase — so the shop check that a policy would make is made here.
     */
    private function assertOwned(Request $request, Supplier $supplier): void
    {
        abort_unless($supplier->user_id === $request->user()->dataOwnerId(), 403);
    }

    /**
     * `exists:suppliers,id` alone would let one shop file its deliveries under
     * another shop's wholesaler, which then leaks that wholesaler's name back
     * through the purchase list.
     */
    private function assertSupplierBelongsToUser(Request $request, ?int $supplierId): void
    {
        if ($supplierId === null) {
            return;
        }

        $owned = Supplier::query()
            ->where('id', $supplierId)
            ->where('user_id', $request->user()->dataOwnerId())
            ->exists();

        if (! $owned) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Supplier not found.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'notes' => $supplier->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'reference' => $purchase->reference,
            'invoice_number' => $purchase->invoice_number,
            'supplier_id' => $purchase->supplier_id,
            'supplier' => $purchase->relationLoaded('supplier') && $purchase->supplier
                ? [
                    'id' => $purchase->supplier->id,
                    'name' => $purchase->supplier->name,
                    // On the invoice screen the shopkeeper is holding the
                    // supplier's paper bill and reaching for the phone.
                    'phone' => $purchase->supplier->phone,
                ]
                : null,
            'purchase_date' => $purchase->purchase_date?->format('Y-m-d'),
            'subtotal' => (float) $purchase->subtotal,
            'discount_amount' => (float) $purchase->discount_amount,
            'total' => (float) $purchase->total,
            'amount_paid' => (float) $purchase->amount_paid,
            'outstanding_amount' => $purchase->outstandingAmount(),
            'payment_status' => $purchase->payment_status,
            'note' => $purchase->note,
            'items_count' => $purchase->items_count ?? $purchase->items()->count(),
            'created_at' => $purchase->created_at?->toIso8601String(),
            'items' => $purchase->relationLoaded('items')
                ? $purchase->items->map(fn (PurchaseItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'unit_type' => $item->unit_type,
                    'base_unit' => $item->base_unit,
                    'price_unit' => $item->price_unit,
                    // Two figures for the same delivery. `quantity` and
                    // `unit_cost` are per base unit and are what the margin
                    // arithmetic uses; the display pair is the same delivery
                    // quoted the way the shop bought it, which is the only one
                    // a shopkeeper would recognise on a screen.
                    'quantity' => (float) $item->quantity,
                    'display_quantity' => Unit::fromBase((float) $item->quantity, $item->price_unit),
                    'quantity_label' => $item->quantityLabel(),
                    'unit_cost' => (float) $item->unit_cost,
                    'display_unit_cost' => Unit::priceFromBase((float) $item->unit_cost, $item->price_unit),
                    'unit_cost_label' => $item->unitCostLabel(),
                    'line_total' => (float) $item->line_total,
                ])->values()
                : [],
            'payments' => $purchase->relationLoaded('payments')
                ? $this->paymentHistory($purchase)
                : [],
        ];
    }

    /**
     * The instalments, each with what was still owed once it had been paid.
     *
     * The balance is walked forward in the order the money actually moved. The
     * screen may well read the list newest first — that is the useful order —
     * but computing it that way would print the wrong figure against every row
     * but the last.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentHistory(Purchase $purchase): array
    {
        $balance = (float) $purchase->total;

        return $purchase->payments->map(function (PurchasePayment $payment) use (&$balance) {
            $balance = max(0, round($balance - (float) $payment->amount, 2));

            return [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'paid_by_name' => $payment->paid_by_name,
                'note' => $payment->note,
                'recorded_by' => $payment->recorded_by,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'balance_after' => $balance,
            ];
        })->values()->all();
    }
}
