<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\CatalogImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class CatalogImageTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real local disk, isolated under storage/framework/testing — uploads
        // genuinely land on it, they just do not pollute the dev catalogue.
        Storage::fake('public');
    }

    private function seller(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    private function product(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Basmati Rice 5kg',
            'price' => 2400,
            'track_stock' => true,
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ], $attributes));
    }

    /* ------------------------------------------------------------- uploads */

    public function test_a_product_image_lands_on_disk_and_comes_back_as_a_url(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image('shelf-photo.jpg', 600, 400),
            ])
            ->assertOk();

        $path = $product->fresh()->image_path;

        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertStringStartsWith(CatalogImageStore::PRODUCT_DIR.'/', $path);
        $this->assertStringEndsWith('.jpg', $path);

        $url = $response->json('product.image_url');
        $this->assertNotNull($url);
        $this->assertStringEndsWith($path, $url);
    }

    public function test_a_category_image_lands_on_disk_and_comes_back_as_a_url(): void
    {
        $user = $this->seller();
        $category = ProductCategory::create([
            'user_id' => $user->id,
            'name' => 'Cold Drinks',
            'slug' => 'cold-drinks',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/product-categories/{$category->id}/image", [
                'image' => UploadedFile::fake()->image('drinks.png', 300, 300),
            ])
            ->assertOk();

        $path = $category->fresh()->image_path;

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertStringStartsWith(CatalogImageStore::CATEGORY_DIR.'/', $path);
        $this->assertStringEndsWith($path, $response->json('category.image_url'));

        // The till's category rail reads the list endpoint, not the single one.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/product-categories')
            ->assertOk()
            ->assertJsonPath('categories.0.image_url', $response->json('category.image_url'));
    }

    public function test_replacing_an_image_removes_the_file_it_replaced(): void
    {
        $user = $this->seller();
        $product = $this->product($user);
        $upload = fn (string $name) => $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image($name, 200, 200),
            ])
            ->assertOk();

        $upload('first.jpg');
        $first = $product->fresh()->image_path;

        $upload('second.jpg');
        $second = $product->fresh()->image_path;

        $this->assertNotSame($first, $second);
        $this->assertFalse(Storage::disk('public')->exists($first), 'the replaced file was orphaned');
        $this->assertTrue(Storage::disk('public')->exists($second));
    }

    public function test_removing_an_image_clears_the_column_and_the_file(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image('gone.jpg', 200, 200),
            ])
            ->assertOk();

        $path = $product->fresh()->image_path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}/image")
            ->assertOk()
            ->assertJsonPath('product.image_url', null);

        $this->assertNull($product->fresh()->image_path);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_deleting_a_product_takes_its_image_with_it(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image('bye.jpg', 200, 200),
            ])
            ->assertOk();

        $path = $product->fresh()->image_path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();

        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    /* ------------------------------------------------------------ security */

    public function test_a_php_payload_wearing_a_jpeg_header_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        // finfo reports this as image/jpeg — the SOI marker is all libmagic
        // looks at — so it sails past the `image` and `mimes` rules. Only the
        // getimagesize() gate, which has to read real dimensions, stops it.
        $payload = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            .'<?php system($_GET["c"]); ?>'.str_repeat('A', 64);

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->createWithContent('innocent.jpg', $payload),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertNull($product->fresh()->image_path);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_an_svg_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        // An SVG served from our own origin runs its own <script> — it is a
        // stored-XSS vector, not a picture.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->create('huge.jpg', CatalogImageStore::MAX_KILOBYTES + 1, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_the_stored_filename_is_not_taken_from_the_upload(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image('../../../evil .php.jpg', 120, 120),
            ])
            ->assertOk();

        $path = $product->fresh()->image_path;

        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString(' ', $path);
        $this->assertSame(CatalogImageStore::PRODUCT_DIR.'/product-'.$product->id, Str::beforeLast($path, '-'));
    }

    public function test_another_shop_cannot_attach_an_image_to_your_product(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $product = $this->product($mine);

        $this->actingAs($theirs, 'sanctum')
            ->post("/api/products/{$product->id}/image", [
                'image' => UploadedFile::fake()->image('theirs.jpg', 100, 100),
            ])
            ->assertForbidden();

        $this->actingAs($theirs, 'sanctum')
            ->deleteJson("/api/products/{$product->id}/image")
            ->assertForbidden();

        $this->assertNull($product->fresh()->image_path);
    }

    public function test_a_guest_cannot_upload(): void
    {
        $product = $this->product($this->seller());

        $this->post("/api/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('anon.jpg', 100, 100),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_products_without_an_image_report_a_null_url(): void
    {
        $user = $this->seller();
        $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('products.0.image_url', null);
    }
}
