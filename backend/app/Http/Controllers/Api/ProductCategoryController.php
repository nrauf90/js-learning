<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductCategory::class);

        $categories = ProductCategory::query()
            ->where('user_id', $request->user()->id)
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'products_count' => $c->products_count,
            ]);

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductCategory::class);

        $validated = $request->validate($this->rules($request));

        $category = ProductCategory::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($request, $validated['name']),
        ]);

        return response()->json(['category' => $this->payload($category)], 201);
    }

    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('update', $productCategory);

        $validated = $request->validate($this->rules($request, $productCategory));

        $productCategory->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($request, $validated['name'], $productCategory),
        ]);

        return response()->json(['category' => $this->payload($productCategory)]);
    }

    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('delete', $productCategory);

        // Products survive; the FK is nullOnDelete, so they just become
        // uncategorised rather than disappearing from the till.
        $productCategory->delete();

        return response()->json(['message' => 'Deleted']);
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
                    ->where(fn ($q) => $q->where('user_id', $request->user()->id))
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
                ->where('user_id', $request->user()->id)
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
        ];
    }
}
