<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Pos\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    public function __construct(private SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()
            ->with('category:id,name')
            ->where('user_id', $request->user()->id)
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
            ->where('user_id', $request->user()->id)
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

        $validated = $request->validate($this->rules($request));

        $this->assertCategoryBelongsToUser($request, $validated['product_category_id'] ?? null);

        $product = Product::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        // Record where the count started, so the movement history explains the
        // current balance from the very first row rather than starting mid-air.
        if ($product->track_stock && $product->stock_quantity !== 0) {
            $product->stockMovements()->create([
                'user_id' => $product->user_id,
                'type' => 'initial',
                'quantity_delta' => $product->stock_quantity,
                'balance_after' => $product->stock_quantity,
                'note' => 'Opening stock',
            ]);
        }

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

        $validated = $request->validate($this->rules($request, $product));

        $this->assertCategoryBelongsToUser($request, $validated['product_category_id'] ?? null);

        // Stock is deliberately not writable here — it only moves through
        // sales, refunds and the adjust endpoint, so every change leaves a
        // stock_movements row behind.
        unset($validated['stock_quantity']);

        $product->update($validated);
        $product->load('category:id,name');

        return response()->json(['product' => $this->payload($product)]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        // Past sale_items keep their name/price snapshot and simply lose the
        // product_id, so receipts and reports survive the deletion.
        $product->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', Rule::in(['restock', 'adjustment'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $product->track_stock) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['This product does not track stock.'],
            ]);
        }

        $updated = $this->sales->adjustStock(
            $request->user(),
            $product,
            $validated['quantity_delta'],
            $validated['type'],
            $validated['note'] ?? null,
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
        $userId = $request->user()->id;

        $unique = fn (string $column) => Rule::unique('products', $column)
            ->where(fn ($q) => $q->where('user_id', $userId))
            ->ignore($product?->id);

        return [
            'name' => [$required, 'string', 'max:160'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999.99'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'sku' => ['nullable', 'string', 'max:64', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:64', $unique('barcode')],
            'product_category_id' => ['nullable', 'integer'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
            ->where('user_id', $request->user()->id)
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
            'price' => (float) $product->price,
            'cost' => $product->cost === null ? null : (float) $product->cost,
            'track_stock' => $product->track_stock,
            'stock_quantity' => $product->stock_quantity,
            'low_stock_threshold' => $product->low_stock_threshold,
            'low_stock' => $product->isLowStock(),
            'is_active' => $product->is_active,
            'category' => $product->relationLoaded('category') && $product->category
                ? ['id' => $product->category->id, 'name' => $product->category->name]
                : null,
            'product_category_id' => $product->product_category_id,
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }
}
