<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use App\Models\User;
use Database\Seeders\PosProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class PosRefundTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PosProductSeeder::class);
    }

    public function test_partial_line_level_refund(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $product = PosProduct::where('sku', 'TEA-001')->firstOrFail();

        $clientSaleId = (string) Str::uuid();
        $line1Id = (string) Str::uuid();
        $line2Id = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/sales/sync', [
                'sales' => [[
                    'client_sale_id' => $clientSaleId,
                    'idempotency_key' => 'sale-refund-1',
                    'subtotal' => 200,
                    'total' => 200,
                    'sold_at' => '2026-08-06T10:00:00Z',
                    'lines' => [
                        [
                            'client_line_id' => $line1Id,
                            'pos_product_id' => $product->id,
                            'product_name' => 'Chai',
                            'unit_price' => 80,
                            'quantity' => 2,
                            'line_total' => 160,
                        ],
                        [
                            'client_line_id' => $line2Id,
                            'product_name' => 'Misc',
                            'unit_price' => 40,
                            'quantity' => 1,
                            'line_total' => 40,
                        ],
                    ],
                ]],
            ])
            ->assertOk();

        $refundResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/refunds/sync', [
                'refunds' => [[
                    'client_refund_id' => (string) Str::uuid(),
                    'idempotency_key' => 'refund-key-1',
                    'client_sale_id' => $clientSaleId,
                    'reason' => 'Customer returned 1 chai',
                    'refunded_at' => '2026-08-06T11:00:00Z',
                    'lines' => [[
                        'client_line_id' => $line1Id,
                        'quantity_refunded' => 1,
                        'amount_refunded' => 80,
                    ]],
                ]],
            ]);

        $refundResponse->assertOk()
            ->assertJsonPath('results.0.idempotent_replay', false)
            ->assertJsonPath('results.0.refund.total_refunded', 80);

        $sale = PosSale::where('client_sale_id', $clientSaleId)->firstOrFail();
        $this->assertEquals('partially_refunded', $sale->status);

        $line = PosSaleLine::where('client_line_id', $line1Id)->firstOrFail();
        $this->assertEquals(1, $line->quantity_refunded);
    }

    public function test_full_refund_marks_sale_refunded(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $clientSaleId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/sales/sync', [
                'sales' => [[
                    'client_sale_id' => $clientSaleId,
                    'idempotency_key' => 'sale-full-refund',
                    'subtotal' => 50,
                    'total' => 50,
                    'sold_at' => '2026-08-06T10:00:00Z',
                    'lines' => [[
                        'client_line_id' => $lineId,
                        'product_name' => 'Biscuit',
                        'unit_price' => 50,
                        'quantity' => 1,
                        'line_total' => 50,
                    ]],
                ]],
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/refunds/sync', [
                'refunds' => [[
                    'client_refund_id' => (string) Str::uuid(),
                    'idempotency_key' => 'refund-full',
                    'client_sale_id' => $clientSaleId,
                    'refunded_at' => '2026-08-06T11:00:00Z',
                    'lines' => [[
                        'client_line_id' => $lineId,
                        'quantity_refunded' => 1,
                        'amount_refunded' => 50,
                    ]],
                ]],
            ])
            ->assertOk();

        $sale = PosSale::where('client_sale_id', $clientSaleId)->firstOrFail();
        $this->assertEquals('refunded', $sale->status);
    }

    public function test_refund_sync_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $clientSaleId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pos/sales/sync', [
                'sales' => [[
                    'client_sale_id' => $clientSaleId,
                    'idempotency_key' => 'sale-idem-refund',
                    'subtotal' => 80,
                    'total' => 80,
                    'sold_at' => '2026-08-06T10:00:00Z',
                    'lines' => [[
                        'client_line_id' => $lineId,
                        'product_name' => 'Chai',
                        'unit_price' => 80,
                        'quantity' => 1,
                        'line_total' => 80,
                    ]],
                ]],
            ]);

        $payload = [
            'refunds' => [[
                'client_refund_id' => (string) Str::uuid(),
                'idempotency_key' => 'refund-idem-1',
                'client_sale_id' => $clientSaleId,
                'refunded_at' => '2026-08-06T11:00:00Z',
                'lines' => [[
                    'client_line_id' => $lineId,
                    'quantity_refunded' => 1,
                    'amount_refunded' => 80,
                ]],
            ]],
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/pos/refunds/sync', $payload)->assertOk();
        $replay = $this->actingAs($user, 'sanctum')->postJson('/api/pos/refunds/sync', $payload);

        $replay->assertOk()->assertJsonPath('results.0.idempotent_replay', true);
    }
}
