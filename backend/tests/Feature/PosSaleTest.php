<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\PosSale;
use App\Models\User;
use Database\Seeders\PosProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PosProductSeeder::class);
    }

    public function test_subscribed_user_can_list_pos_products(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/pos/products')
            ->assertOk()
            ->assertJsonCount(8, 'products')
            ->assertJsonFragment(['sku' => 'TEA-001']);
    }

    public function test_sync_sale_creates_sale_with_lines(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $product = PosProduct::where('sku', 'TEA-001')->firstOrFail();
        $clientSaleId = (string) Str::uuid();
        $clientLineId = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/sales/sync', [
                'sales' => [[
                    'client_sale_id' => $clientSaleId,
                    'idempotency_key' => 'sale-key-1',
                    'subtotal' => 160,
                    'tax' => 0,
                    'total' => 160,
                    'payment_method' => 'cash',
                    'sold_at' => '2026-08-06T10:00:00Z',
                    'sync_source' => 'offline',
                    'lines' => [[
                        'client_line_id' => $clientLineId,
                        'pos_product_id' => $product->id,
                        'product_name' => 'Chai',
                        'sku' => 'TEA-001',
                        'unit_price' => 80,
                        'quantity' => 2,
                        'line_total' => 160,
                    ]],
                ]],
            ]);

        $response->assertOk()
            ->assertJsonPath('results.0.idempotent_replay', false)
            ->assertJsonPath('results.0.sale.total', 160)
            ->assertJsonPath('results.0.sale.sync_source', 'offline');

        $this->assertDatabaseHas('pos_sales', [
            'user_id' => $user->id,
            'client_sale_id' => $clientSaleId,
            'idempotency_key' => 'sale-key-1',
        ]);
    }

    public function test_sync_sale_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $product = PosProduct::firstOrFail();
        $payload = [
            'sales' => [[
                'client_sale_id' => (string) Str::uuid(),
                'idempotency_key' => 'idem-sale-1',
                'subtotal' => 80,
                'total' => 80,
                'sold_at' => '2026-08-06T10:00:00Z',
                'lines' => [[
                    'client_line_id' => (string) Str::uuid(),
                    'pos_product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => 80,
                    'quantity' => 1,
                    'line_total' => 80,
                ]],
            ]],
        ];

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/pos/sales/sync', $payload);
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/pos/sales/sync', $payload);

        $first->assertOk()->assertJsonPath('results.0.idempotent_replay', false);
        $second->assertOk()->assertJsonPath('results.0.idempotent_replay', true);
        $this->assertEquals(1, PosSale::count());
    }

    public function test_unsubscribed_user_cannot_access_pos(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/pos/products')
            ->assertStatus(402);
    }
}
