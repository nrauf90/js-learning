<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Services\ActivityLogger;
use App\Services\CatalogImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    public function __construct(
        private ActivityLogger $activity,
        private CatalogImageStore $images,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductCategory::class);

        // Scoped to the shop owner rather than the caller: staff work their
        // owner's catalogue, so a cashier must see the same categories the
        // till's rail does. See User::dataOwnerId().
        $categories = ProductCategory::query()
            ->where('user_id', $request->user()->dataOwnerId())
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $c) => [
                ...$this->payload($c),
                'products_count' => $c->products_count,
            ]);

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductCategory::class);

        $validated = $request->validate($this->rules($request));

        $category = ProductCategory::create([
            'user_id' => $request->user()->dataOwnerId(),
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($request, $validated['name']),
        ]);

        $this->activity->created($request->user(), $category);

        return response()->json(['category' => $this->payload($category)], 201);
    }

    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('update', $productCategory);

        $validated = $request->validate($this->rules($request, $productCategory));

        $before = $this->activity->snapshot($productCategory);

        $productCategory->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($request, $validated['name'], $productCategory),
        ]);

        $this->activity->updated($request->user(), $productCategory, $before);

        return response()->json(['category' => $this->payload($productCategory)]);
    }

    public function destroy(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('delete', $productCategory);

        // Products survive; the FK is nullOnDelete, so they just become
        // uncategorised rather than disappearing from the till.
        $productCategory->delete();
        $this->images->delete($productCategory->image_path);
        $this->activity->deleted($request->user(), $productCategory);

        return response()->json(['message' => 'Deleted']);
    }

    /** Picture for the till's category rail. See ProductController::uploadImage. */
    public function uploadImage(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('update', $productCategory);

        $request->validate($this->images->rules());

        $before = $this->activity->snapshot($productCategory);

        $path = $this->images->store(
            $request->file('image'),
            CatalogImageStore::CATEGORY_DIR,
            'category-'.$productCategory->getKey(),
            $productCategory->image_path,
        );

        $productCategory->update(['image_path' => $path]);
        $this->activity->updated($request->user(), $productCategory, $before);

        return response()->json(['category' => $this->payload($productCategory)]);
    }

    public function destroyImage(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('update', $productCategory);

        if ($productCategory->image_path) {
            $before = $this->activity->snapshot($productCategory);
            $removed = $productCategory->image_path;

            $productCategory->update(['image_path' => null]);
            $this->images->delete($removed);
            $this->activity->updated($request->user(), $productCategory, $before);
        }

        return response()->json(['category' => $this->payload($productCategory)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?ProductCategory $category = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('product_categories', 'name')
                    ->where(fn ($q) => $q->where('user_id', $request->user()->dataOwnerId()))
                    ->ignore($category?->id),
            ],
        ];
    }

    /**
     * Slugs are unique per user, and two different names can slug to the same
     * string ("Cold Drinks" / "cold-drinks"), so suffix until it is free.
     */
    private function uniqueSlug(Request $request, string $name, ?ProductCategory $ignore = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (
            ProductCategory::query()
                ->where('user_id', $request->user()->dataOwnerId())
                ->where('slug', $slug)
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProductCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            // Same key as the product payload — the till's category rail and the
            // catalogue tiles read one field name, not two.
            'image_url' => $category->imageUrl(),
        ];
    }
}
