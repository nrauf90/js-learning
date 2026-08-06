<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
use Database\Seeders\PosProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class AdminPosTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PosProductSeeder::class);
    }

    public function test_admin_can_access_pos_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pos/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats' => ['today_sales', 'today_refunds', 'active_products']]);
    }

    public function test_non_admin_cannot_access_pos_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pos/dashboard')
            ->assertForbidden();
    }

    public function test_admin_can_create_product(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/pos/products', [
                'sku' => 'NEW-001',
                'name' => 'New Item',
                'price' => 99.5,
            ])
            ->assertCreated()
            ->assertJsonPath('product.sku', 'NEW-001');

        $this->assertDatabaseHas('pos_products', ['sku' => 'NEW-001']);
    }

    public function test_admin_can_update_product(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $product = PosProduct::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/pos/products/'.$product->id, [
                'price' => 999,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('product.price', '999.00');
    }
}
