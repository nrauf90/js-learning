<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GroceryCatalogSeeder extends Seeder
{
    /**
     * Which shop gets the catalogue — a user id or an email. Set the property
     * when calling the seeder from code, or pass CATALOG_USER in the
     * environment; with neither, the first user on record is used.
     */
    public int|string|null $target = null;

    private const MANIFEST = 'database/data/grocery-catalog.json';

    public function run(): void
    {
        $user = $this->resolveUser();
        $catalog = $this->loadManifest();

        // image_path is added by its own migration. The catalogue is still
        // worth seeding without it, so probe rather than assume.
        $categoryHasImage = Schema::hasColumn('product_categories', 'image_path');
        $productHasImage = Schema::hasColumn('products', 'image_path');

        $categories = 0;
        $products = 0;
        $missingFiles = 0;

        DB::transaction(function () use ($user, $catalog, $categoryHasImage, $productHasImage, &$categories, &$products, &$missingFiles) {
            foreach ($catalog['categories'] as $row) {
                // (user_id, slug) is the table's unique key — matching on it is
                // what makes a re-run an update instead of a duplicate.
                $category = ProductCategory::query()->updateOrCreate(
                    ['user_id' => $user->id, 'slug' => $row['slug']],
                    ['name' => $row['name']]
                );
                $categories++;

                $missingFiles += $this->applyImage($category, $row['image_path'] ?? null, $categoryHasImage);

                foreach ($row['products'] as $item) {
                    // products carries per-user unique indexes on both sku and
                    // barcode; sku is the stable identity here, so match on it
                    // and let barcode ride along as an attribute.
                    $product = Product::query()->updateOrCreate(
                        ['user_id' => $user->id, 'sku' => $item['sku']],
                        [
                            'product_category_id' => $category->id,
                            'name' => $item['name'],
                            'barcode' => $item['barcode'],
                            'price' => $item['price'],
                            'cost' => $item['cost'],
                            'track_stock' => $item['track_stock'],
                            'stock_quantity' => $item['stock_quantity'],
                            'low_stock_threshold' => $item['low_stock_threshold'],
                            'is_active' => $item['is_active'],
                        ]
                    );
                    $products++;

                    $missingFiles += $this->applyImage($product, $item['image_path'] ?? null, $productHasImage);
                }
            }
        });

        $this->command->info("Grocery catalogue seeded for {$user->email}.");
        $this->command->info("Categories: {$categories}  Products: {$products}");

        if (! $categoryHasImage || ! $productHasImage) {
            $this->command->warn('image_path column missing — image paths were skipped. Re-run after the migration lands.');
        }

        if ($missingFiles > 0) {
            $this->command->warn("{$missingFiles} image file(s) referenced by the manifest are not on the public disk.");
        }
    }

    /**
     * Writes image_path when the column exists. It is deliberately not
     * mass-assignable on the models, hence forceFill; save() is a no-op when
     * the value is unchanged, so re-runs stay quiet.
     */
    private function applyImage(ProductCategory|Product $model, ?string $path, bool $columnExists): int
    {
        if (! $columnExists || $path === null) {
            return 0;
        }

        $missing = Storage::disk('public')->exists($path) ? 0 : 1;

        $model->forceFill(['image_path' => $path])->save();

        return $missing;
    }

    private function resolveUser(): User
    {
        $target = $this->target ?? env('CATALOG_USER');

        if (filled($target)) {
            $user = is_numeric($target)
                ? User::query()->find((int) $target)
                : User::query()->where('email', $target)->first();

            if (! $user) {
                throw new RuntimeException("No user matches CATALOG_USER \"{$target}\".");
            }

            return $user;
        }

        $user = User::query()->orderBy('id')->first();

        if (! $user) {
            throw new RuntimeException(
                'No users exist — register one (or run AdminUserSeeder) before seeding the grocery catalogue.'
            );
        }

        return $user;
    }

    /**
     * @return array{categories: array<int, array<string, mixed>>}
     */
    private function loadManifest(): array
    {
        $path = base_path(self::MANIFEST);

        if (! is_file($path)) {
            throw new RuntimeException('Catalogue manifest missing at '.self::MANIFEST);
        }

        $catalog = json_decode((string) file_get_contents($path), true);

        if (! is_array($catalog) || ! isset($catalog['categories']) || ! is_array($catalog['categories'])) {
            throw new RuntimeException('Catalogue manifest at '.self::MANIFEST.' is not valid JSON.');
        }

        return $catalog;
    }
}
