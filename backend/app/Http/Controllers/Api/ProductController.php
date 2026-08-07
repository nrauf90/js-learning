<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\CatalogImageStore;
use App\Services\Pos\SaleService;
use App\Support\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /**
     * The unit rules fail three ways — no price unit given, something that is
     * not a unit at all, and a unit belonging to a different kind of goods —
     * but the shopkeeper's fix is the same each time, so they say so once.
     */
    private const UNIT_MESSAGES = [
        'unit_type.in' => 'Choose whether this is sold by the piece, by weight or by volume.',
        'price_unit.in' => 'Choose a price unit that matches the kind of goods.',
        'price_unit.required_with' => 'Choose a price unit that matches the kind of goods.',
        'price.required_with' => 'Enter the price again in the new unit.',
    ];

    public function __construct(
        private SaleService $sales,
        private ActivityLogger $activity,
        private CatalogImageStore $images,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'expiring' => ['nullable', 'boolean'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        // dataOwnerId(), not id: a staff account works its shop owner's
        // catalogue, so scoping to the cashier would hand them an empty till.
        $query = Product::query()
            ->with('category:id,name')
            ->where('user_id', $request->user()->dataOwnerId())
            ->search($validated['search'] ?? null)
            ->orderBy('name');

        if (isset($validated['category_id'])) {
            $query->where('product_category_id', $validated['category_id']);
        }

        if (isset($validated['active'])) {
            $query->where('is_active', $validated['active']);
        }

        if (! empty($validated['low_stock'])) {
            $query->where('track_stock', true)
                ->where('low_stock_threshold', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        // The dairy fridge and the bread rack, a month ahead by default — long
        // enough to still be able to sell the stock down rather than bin it.
        if (! empty($validated['expiring'])) {
            $query->expiring((int) ($validated['expiring_days'] ?? Product::EXPIRY_SOON_DAYS));
        }

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginator = $query->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'products' => collect($paginator->items())->map(fn (Product $p) => $this->payload($p)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Exact-match lookup for the scanner. Kept separate from index() so a
     * barcode scan is one round trip that either finds the item or doesn't,
     * rather than a list the cashier has to pick from.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $product = Product::query()
            ->where('user_id', $request->user()->dataOwnerId())
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $validated['code'])->orWhere('sku', $validated['code']))
            ->first();

        if (! $product) {
            return response()->json(['message' => 'No product matches that code.'], 404);
        }

        return response()->json(['product' => $this->payload($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $validated = $request->validate($this->rules($request), self::UNIT_MESSAGES);

        $this->assertCategoryBelongsToUser($request, $validated['product_category_id'] ?? null);

        $product = Product::create([
            ...$this->toBaseUnits($validated),
            // Filed under the shop owner, so a product added by staff shows up
            // in the shop's catalogue rather than in a private one of theirs.
            'user_id' => $request->user()->dataOwnerId(),
        ]);

        // Record where the count started, so the movement history explains the
        // current balance from the very first row rather than starting mid-air.
        // Compared as a float because stock is now a decimal: "0.000" is not
        // identical to 0, and every product would open with a zero movement.
        if ($product->track_stock && (float) $product->stock_quantity !== 0.0) {
            $product->stockMovements()->create([
                'user_id' => $product->user_id,
                'type' => 'initial',
                'quantity_delta' => $product->stock_quantity,
                'balance_after' => $product->stock_quantity,
                'note' => 'Opening stock',
            ]);
        }

        $this->activity->created($request->user(), $product);

        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);
        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate($this->rules($request, $product), self::UNIT_MESSAGES);

        $this->assertCategoryBelongsToUser($request, $validated['product_category_id'] ?? null);

        $validated = $this->toBaseUnits($validated, $product);

        // Stock is deliberately not writable here — it only moves through
        // sales, refunds and the adjust endpoint, so every change leaves a
        // stock_movements row behind.
        unset($validated['stock_quantity']);

        $before = $this->activity->snapshot($product);
        $product->update($validated);
        $this->activity->updated($request->user(), $product, $before);

        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        // Past sale_items keep their name/price snapshot and simply lose the
        // product_id, so receipts and reports survive the deletion.
        $product->delete();
        $this->images->delete($product->image_path);
        $this->activity->deleted($request->user(), $product);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Replaces the product's picture. Kept off update() because a multipart
     * body and a JSON body are different enough that mixing them would mean the
     * catalogue form could no longer send a plain PUT.
     */
    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $request->validate($this->images->rules());

        $before = $this->activity->snapshot($product);

        $path = $this->images->store(
            $request->file('image'),
            CatalogImageStore::PRODUCT_DIR,
            'product-'.$product->getKey(),
            $product->image_path,
        );

        $product->update(['image_path' => $path]);
        $this->activity->updated($request->user(), $product, $before);

        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)]);
    }

    public function destroyImage(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        if ($product->image_path) {
            $before = $this->activity->snapshot($product);
            $removed = $product->image_path;

            $product->update(['image_path' => null]);
            $this->images->delete($removed);
            $this->activity->updated($request->user(), $product, $before);
        }

        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)]);
    }

    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'type' => ['required', Rule::in(['restock', 'adjustment'])],
            'reason' => ['nullable', Rule::in(StockMovement::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'reason.in' => 'Choose why the stock is coming off the shelf.',
        ]);

        if (! $product->track_stock) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['This product does not track stock.'],
            ]);
        }

        // Counted out in the unit the shop quotes in: "2" against atta priced
        // by the kilo is two kilos off the sack, not two grams.
        $delta = Unit::toBase((float) $validated['quantity_delta'], $product->price_unit);

        // not_in:0 only catches a literal zero. Now that the delta is a decimal
        // a figure smaller than the base unit rounds away to nothing here, and
        // would file a stock movement that moved no stock.
        if ($delta === 0.0) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['That amount is too small to change the count.'],
            ]);
        }

        $reason = $validated['reason'] ?? null;

        // Stock leaving without a sale is either a loss or a bad count, and a
        // shopkeeper cannot tell those apart at the end of the month from a
        // free-text note. Adding stock needs no excuse — a delivery explains
        // itself, and asking would only slow the one path that is always benign.
        if ($delta < 0 && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['Say why the stock is coming off: damaged, expired, spoiled, theft, sample, or a count correction.'],
            ]);
        }

        $from = (float) $product->stock_quantity;

        // Priced before the write, off the cost the product carries right now:
        // the value of what was thrown away is a fact about today, and reading
        // it back off the product next month would revalue the loss every time
        // a supplier changed a price.
        $costValue = StockMovement::valueAtCost(
            $delta,
            $product->cost === null ? null : (float) $product->cost,
        );

        // One transaction around both writes so the product stays row-locked
        // while the movement is labelled. SaleService owns the audited write and
        // knows nothing about wastage; without the outer lock a sale on the same
        // product could slip its own movement in between, and the reason would
        // be stamped on the wrong row.
        $updated = DB::transaction(function () use ($request, $product, $delta, $validated, $reason, $costValue) {
            $updated = $this->sales->adjustStock(
                $request->user(),
                $product,
                $delta,
                $validated['type'],
                $validated['note'] ?? null,
            );

            $updated->stockMovements()->latest('id')->first()?->update([
                'reason' => $reason,
                'cost_value' => $costValue,
            ]);

            return $updated;
        });

        // The stock_movements row already records the delta for the ledger; this
        // records the person, which that table has no column for.
        $this->activity->stockAdjusted(
            $request->user(),
            $updated,
            $from,
            (float) $updated->stock_quantity,
            $validated['type'],
            $validated['note'] ?? null,
            $validated['reason'] ?? null,
        );

        $updated->load('category:id,name');

        return response()->json(['product' => $this->payload($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Product $product = null): array
    {
        $required = $product ? 'sometimes' : 'required';
        $userId = $request->user()->dataOwnerId();

        // Moving a product from pieces to weight changes what its price means —
        // Rs 250 a packet is not Rs 250 a kilo — so the figure has to be
        // restated alongside the unit rather than reread underneath it.
        $restated = $product ? 'required_with:unit_type' : 'required';

        // Which units a price may be quoted in follows from the kind of goods.
        // "Rs 250 per litre" for daal has no sensible reading, but the divide
        // down to a per-gram price would produce one all the same.
        $unitType = $this->resolveUnitType($request, $product);

        $unique = fn (string $column) => Rule::unique('products', $column)
            ->where(fn ($q) => $q->where('user_id', $userId))
            ->ignore($product?->id);

        return [
            'name' => [$required, 'string', 'max:160'],
            'unit_type' => ['sometimes', Rule::in(Unit::types())],
            // Only demanded once the request declares what kind of goods this
            // is; a product posted without either is sold by the piece, priced
            // by the piece, exactly as before loose goods existed.
            'price_unit' => ['required_with:unit_type', 'string', Rule::in(Unit::codesForType($unitType))],
            // Money and counts alike are entered in `price_unit`: the
            // shopkeeper types 250 for Rs 250 a kilo and 50 for fifty kilos of
            // atta, and toBaseUnits() divides both down before they are stored.
            'price' => [$restated, 'numeric', 'min:0', 'max:9999999.99'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'sku' => ['nullable', 'string', 'max:64', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:64', $unique('barcode')],
            'product_category_id' => ['nullable', 'integer'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'low_stock_threshold' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            // Also quoted in `price_unit`: a peti holding two dozen is typed as
            // "2" against a dozen price and as "24" against a piece price, and
            // both land on the same 24 pieces underneath.
            'pack_size' => ['sometimes', 'numeric', 'min:0.001', 'max:999999'],
            'pack_label' => ['nullable', 'string', 'max:32'],
            'expiry_date' => ['nullable', 'date'],
            'track_expiry' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The kind of goods this request leaves the product selling, which is what
     * `price_unit` has to agree with. An update that says nothing keeps the
     * product's own kind, so a plain price edit is still checked against it.
     */
    private function resolveUnitType(Request $request, ?Product $product): string
    {
        $submitted = $request->input('unit_type');

        return is_string($submitted)
            ? $submitted
            : ($product?->unit_type ?? Unit::TYPE_EACH);
    }

    /**
     * Translate the shopkeeper's figures into the units the database holds.
     *
     * The form is filled in the unit the shop quotes in; everything underneath
     * the API — the till's arithmetic, the stock ledger, every report — works
     * in base units. Converting once, here, is what keeps the two from being
     * mixed up in a dozen places later.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toBaseUnits(array $validated, ?Product $product = null): array
    {
        $unitType = $validated['unit_type'] ?? $product?->unit_type ?? Unit::TYPE_EACH;
        $priceUnit = $validated['price_unit'] ?? $product?->price_unit ?? Unit::baseFor($unitType);

        $validated['unit_type'] = $unitType;
        // Derived, never taken from the request. base_unit is the unit every
        // stored price and count is already expressed in, so a client that got
        // it wrong would restate the value of everything on the shelf.
        $validated['base_unit'] = Unit::baseFor($unitType);
        $validated['price_unit'] = $priceUnit;

        foreach (['price', 'cost'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = Unit::priceToBase((float) $validated[$field], $priceUnit);
            }
        }

        foreach (['stock_quantity', 'low_stock_threshold', 'pack_size'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = Unit::toBase((float) $validated[$field], $priceUnit);
            }
        }

        // Typing a date into the expiry box *is* the request to be warned about
        // it. Leaving the flag off would file the date and then say nothing,
        // which is the one outcome nobody types a date hoping for.
        if (filled($validated['expiry_date'] ?? null) && ! array_key_exists('track_expiry', $validated)) {
            $validated['track_expiry'] = true;
        }

        return $validated;
    }

    /**
     * `exists:product_categories,id` alone would let one shop file its products
     * under another shop's category, which then leaks that category's name back
     * through the product list.
     */
    private function assertCategoryBelongsToUser(Request $request, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $owned = ProductCategory::query()
            ->where('id', $categoryId)
            ->where('user_id', $request->user()->dataOwnerId())
            ->exists();

        if (! $owned) {
            throw ValidationException::withMessages([
                'product_category_id' => ['Category not found.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            // Resolved URL, not the stored path — the till renders this straight
            // into an <img src>. Null when the product has no picture.
            'image_url' => $product->imageUrl(),
            'unit_type' => $product->unit_type,
            'base_unit' => $product->base_unit,
            'price_unit' => $product->price_unit,
            // Two prices for the same money. `price` is per base unit and is
            // what the till multiplies an arbitrary weight by; `display_price`
            // is the same figure quoted the way the shop quotes it, which is
            // the only one the shopkeeper should ever be shown or asked to type.
            'price' => (float) $product->price,
            'display_price' => $product->displayPrice(),
            'cost' => $product->cost === null ? null : (float) $product->cost,
            'display_cost' => $product->displayCost(),
            'unit_price_label' => $product->unitPriceLabel(),
            'track_stock' => $product->track_stock,
            'stock_quantity' => (float) $product->stock_quantity,
            'display_stock' => Unit::fromBase((float) $product->stock_quantity, $product->price_unit),
            // Reads the way the shopkeeper would say it — "1.5 kg", not "1500".
            'stock_label' => Unit::formatQuantity((float) $product->stock_quantity, $product->unit_type),
            'low_stock_threshold' => (float) $product->low_stock_threshold,
            'low_stock' => $product->isLowStock(),
            // Same two-figure split as price and stock: `pack_size` is the base
            // count the ledger works in, `display_pack_size` is what goes back
            // into the box the shopkeeper typed it in.
            'pack_size' => (float) $product->pack_size,
            'display_pack_size' => Unit::fromBase((float) $product->pack_size, $product->price_unit),
            'pack_label' => $product->pack_label,
            'pack_label_text' => $product->packLabelText(),
            'expiry_date' => $product->expiry_date?->toDateString(),
            'track_expiry' => $product->track_expiry,
            'days_to_expiry' => $product->daysToExpiry(),
            'is_expired' => $product->isExpired(),
            'is_expiring_soon' => $product->isExpiringSoon(),
            'is_active' => $product->is_active,
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'product_category_id' => $product->product_category_id,
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }
}
