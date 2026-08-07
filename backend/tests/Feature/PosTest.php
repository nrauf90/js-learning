<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class PosTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    private function seller(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'cost' => 90,
            'track_stock' => true,
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ], $attributes));
    }

    /* ------------------------------------------------------------ access */

    public function test_guest_cannot_reach_the_pos(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->postJson('/api/sales', [])->assertUnauthorized();
    }

    public function test_pos_is_behind_the_subscription_gate(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }

    /* ---------------------------------------------------------- catalogue */

    public function test_creating_a_product_records_its_opening_stock(): void
    {
        $user = $this->seller();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Chips',
                'price' => 60,
                'stock_quantity' => 25,
                'low_stock_threshold' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('product.stock_quantity', 25);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $response->json('product.id'),
            'type' => 'initial',
            'quantity_delta' => 25,
            'balance_after' => 25,
        ]);
    }

    public function test_products_are_scoped_to_their_owner(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $hidden = $this->product($theirs, ['name' => 'Someone elses stock']);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(0, 'products');

        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/products/{$hidden->id}")
            ->assertForbidden();
    }

    public function test_a_product_cannot_be_filed_under_another_sellers_category(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $category = ProductCategory::create([
            'user_id' => $theirs->id,
            'name' => 'Beverages',
            'slug' => 'beverages',
        ]);

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Cola',
                'price' => 120,
                'product_category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_category_id');
    }

    public function test_sku_is_unique_per_seller_but_reusable_across_sellers(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $this->product($theirs, ['sku' => 'SKU-1']);

        // Another shop already uses this SKU — that must not block us.
        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/products', ['name' => 'Cola', 'price' => 120, 'sku' => 'SKU-1'])
            ->assertCreated();

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/products', ['name' => 'Other', 'price' => 50, 'sku' => 'SKU-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');
    }

    public function test_lookup_matches_a_barcode_exactly(): void
    {
        $user = $this->seller();
        $this->product($user, ['barcode' => '8964000123456']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products/lookup?code=8964000123456')
            ->assertOk()
            ->assertJsonPath('product.name', 'Cola 500ml');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products/lookup?code=nope')
            ->assertNotFound();
    }

    public function test_stock_is_not_writable_through_the_update_endpoint(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 130, 'stock_quantity' => 999])
            ->assertOk()
            ->assertJsonPath('product.price', 130)
            ->assertJsonPath('product.stock_quantity', 10);
    }

    public function test_low_stock_filter_returns_only_products_at_or_below_threshold(): void
    {
        $user = $this->seller();
        $this->product($user, ['name' => 'Low', 'stock_quantity' => 2, 'low_stock_threshold' => 2]);
        $this->product($user, ['name' => 'Fine', 'stock_quantity' => 50, 'low_stock_threshold' => 2, 'sku' => 'F1']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products?low_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Low');
    }

    /* --------------------------------------------------------------- sales */

    public function test_a_sale_decrements_stock_and_stays_out_of_the_cash_ledger(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payment_method' => 'cash',
                'amount_tendered' => 500,
            ])
            ->assertCreated()
            ->assertJsonPath('sale.subtotal', 240)
            ->assertJsonPath('sale.total', 240)
            ->assertJsonPath('sale.change_due', 260)
            ->assertJsonPath('sale.payment_status', 'paid');

        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertMatchesRegularExpression('/^S-\d{6}$/', $response->json('sale.reference'));

        // Individual sales no longer post into the cash ledger — the day book
        // records the takings instead, so one sale is not counted twice.
        $sale = Sale::findOrFail($response->json('sale.id'));

        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertNull($sale->cash_entry_id);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'sale_id' => $sale->id,
            'type' => 'sale',
            'quantity_delta' => -2,
            'balance_after' => 8,
        ]);
    }

    public function test_repeated_scans_of_one_product_merge_into_a_single_line(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 5]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                    ['product_id' => $product->id, 'quantity' => 1],
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'sale.items')
            ->assertJsonPath('sale.items.0.quantity', 3);

        $this->assertEquals(2, $product->fresh()->stock_quantity);
    }

    public function test_a_sale_cannot_oversell_tracked_stock(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        // Nothing partially applied — the whole thing rolls back.
        $this->assertEquals(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_duplicate_lines_are_summed_before_the_stock_check(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 2]);

        // Each line alone passes; together they exceed stock.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertUnprocessable();

        $this->assertEquals(2, $product->fresh()->stock_quantity);
    }

    public function test_an_untracked_product_sells_without_moving_stock(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['track_stock' => false, 'stock_quantity' => 0]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 99]],
            ])
            ->assertCreated();

        $this->assertEquals(0, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_another_sellers_product_cannot_be_added_to_a_sale(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $product = $this->product($theirs);

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_an_inactive_product_cannot_be_sold(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable();
    }

    public function test_a_discount_cannot_exceed_the_subtotal(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'discount_amount' => 500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_amount');
    }

    public function test_cash_tendered_below_the_total_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payment_method' => 'cash',
                'amount_tendered' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_tendered');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_fully_discounted_sale_records_no_income(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        // A zero-total sale still moves stock and still gets a row of its own.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'discount_amount' => 120,
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 0);

        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertNull(Sale::firstOrFail()->cash_entry_id);
        $this->assertEquals(9, $product->fresh()->stock_quantity);
    }

    /* ------------------------------------------------------------ refunds */

    public function test_a_refund_restores_stock_and_touches_no_cash_entry(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->json('sale.id');

        $this->assertEquals(7, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cash_entries', 0);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$saleId}/refund")
            ->assertOk()
            ->assertJsonPath('sale.status', 'refunded');

        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertNull(Sale::findOrFail($saleId)->cash_entry_id);

        $this->assertDatabaseHas('stock_movements', [
            'sale_id' => $saleId,
            'type' => 'refund',
            'quantity_delta' => 3,
            'balance_after' => 10,
        ]);
    }

    public function test_a_sale_cannot_be_refunded_twice(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->json('sale.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$saleId}/refund")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$saleId}/refund")->assertUnprocessable();

        // Stock restored exactly once.
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_another_sellers_sale_cannot_be_viewed_or_refunded(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();
        $product = $this->product($theirs);

        $saleId = $this->actingAs($theirs, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->json('sale.id');

        $this->actingAs($mine, 'sanctum')->getJson("/api/sales/{$saleId}")->assertForbidden();
        $this->actingAs($mine, 'sanctum')->postJson("/api/sales/{$saleId}/refund")->assertForbidden();
    }

    /* -------------------------------------------------------------- stock */

    public function test_stock_adjustment_leaves_a_movement_behind(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => 15,
                'type' => 'restock',
                'note' => 'Monday delivery',
            ])
            ->assertOk()
            ->assertJsonPath('product.stock_quantity', 25);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'restock',
            'quantity_delta' => 15,
            'balance_after' => 25,
            'note' => 'Monday delivery',
        ]);
    }

    public function test_stock_cannot_be_adjusted_below_zero(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -5,
                'type' => 'adjustment',
                // Stock coming off now has to say why (see WastageTest); the
                // reason is what makes the count the only thing still wrong.
                'reason' => 'count_correction',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_delta');

        $this->assertEquals(3, $product->fresh()->stock_quantity);
    }

    /* ------------------------------------------------------------ history */

    public function test_deleting_a_product_leaves_past_sale_lines_readable(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['name' => 'Discontinued item']);

        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->json('sale.id');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('sale.items.0.name', 'Discontinued item')
            ->assertJsonPath('sale.items.0.unit_price', 120)
            ->assertJsonPath('sale.items.0.product_id', null);
    }

    public function test_today_summary_counts_completed_sales_only(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 20]);

        foreach ([1, 2] as $qty) {
            $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => $qty]],
                'payment_method' => 'card',
            ])->assertCreated();
        }

        $refundedId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->json('sale.id');
        $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$refundedId}/refund")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales/today')
            ->assertOk()
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('refunds_count', 1)
            ->assertJsonPath('total', 360)
            ->assertJsonPath('by_payment_method.card', 360)
            ->assertJsonPath('by_payment_method.cash', 0);
    }

    public function test_sales_list_is_scoped_to_the_seller(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->actingAs($theirs, 'sanctum')->postJson('/api/sales', [
            'items' => [['product_id' => $this->product($theirs)->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(0, 'sales');
    }

    /* ------------------------------------------------------- offline till */

    public function test_a_replayed_offline_sale_is_recorded_only_once(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'client_uuid' => '3f1a1b6e-1c2d-4e5f-8a9b-0c1d2e3f4a5b',
            'offline' => true,
        ];

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', $payload)
            ->assertCreated();

        // The till retries whenever a POST times out, and cannot know whether
        // the first one landed. The replay must be a no-op, not a second sale.
        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', $payload)
            ->assertOk();

        $this->assertSame($first->json('sale.id'), $second->json('sale.id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_an_offline_sale_keeps_the_time_it_was_rung_up(): void
    {
        $user = $this->seller();
        $product = $this->product($user);
        $soldAt = now()->subHours(5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'client_uuid' => '11111111-2222-4333-8444-555555555555',
                'offline' => true,
                'sold_at' => $soldAt->toIso8601String(),
            ])
            ->assertCreated();

        $sale = Sale::firstOrFail();

        $this->assertSame($soldAt->toDateTimeString(), $sale->sold_at->toDateTimeString());
        // Reports read `sold_at`, so a queued sale lands on the day it was rung
        // up rather than the day the till came back online.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales?date='.$soldAt->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'sales');
    }

    public function test_a_future_or_ancient_sold_at_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        foreach ([now()->addDay(), now()->subDays(60)] as $badDate) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/sales', [
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                    'sold_at' => $badDate->toIso8601String(),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('sold_at');
        }
    }

    public function test_an_offline_sale_may_drive_stock_negative(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 1]);

        // The goods already left the shop while the till was offline; another
        // terminal happened to sell the same last unit. Losing the sale would
        // be worse than a negative count, which is a prompt to recount.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'client_uuid' => '99999999-8888-4777-8666-555555555555',
                'offline' => true,
            ])
            ->assertCreated();

        $this->assertEquals(-2, $product->fresh()->stock_quantity);
    }

    public function test_an_online_sale_still_refuses_to_oversell(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 1]);

        // Regression guard: the negative-stock allowance is for offline replays
        // only and must not leak into the normal path.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertUnprocessable();

        $this->assertEquals(1, $product->fresh()->stock_quantity);
    }

    /* ---------------------------------------------------- partial refunds */

    public function test_a_partial_refund_returns_some_units_and_their_share_of_the_money(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $sale = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->json('sale');

        $itemId = Sale::findOrFail($sale['id'])->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('sale.status', 'partially_refunded')
            ->assertJsonPath('sale.refunded_amount', 120)
            ->assertJsonPath('sale.items.0.refunded_quantity', 1)
            ->assertJsonPath('sale.items.0.refundable_quantity', 2);

        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_partial_refunds_accumulate_until_the_sale_is_fully_refunded(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->json('sale.id');

        $itemId = Sale::findOrFail($saleId)->items->first()->id;
        $refund = fn () => $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$saleId}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ]);

        $refund()->assertOk()->assertJsonPath('sale.status', 'partially_refunded');
        $refund()->assertOk()->assertJsonPath('sale.status', 'refunded');

        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertNull(Sale::findOrFail($saleId)->cash_entry_id);

        // Nothing left to give back.
        $refund()->assertUnprocessable();
    }

    public function test_a_partial_refund_applies_the_ticket_discount_proportionally(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        // 2 x 120 = 240, less a 40 discount = 200 taken. One unit back is worth
        // its discounted share (100), not the 120 on the price tag.
        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'discount_amount' => 40,
            ])
            ->json('sale.id');

        $itemId = Sale::findOrFail($saleId)->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$saleId}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('sale.refunded_amount', 100);
    }

    public function test_a_refund_cannot_return_more_units_than_were_sold(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $saleId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->json('sale.id');

        $itemId = Sale::findOrFail($saleId)->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$saleId}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 5]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertSame('completed', Sale::findOrFail($saleId)->status);
    }

    public function test_a_refund_rejects_a_line_from_another_sale(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $post = fn () => $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json('sale.id');

        $firstId = $post();
        $secondId = $post();
        $foreignItemId = Sale::findOrFail($secondId)->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$firstId}/refund", [
                'items' => [['sale_item_id' => $foreignItemId, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_stock_movements_track_the_running_balance(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson("/api/products/{$product->id}/stock", [
            'quantity_delta' => 6,
            'type' => 'restock',
        ])->assertOk();

        $balances = StockMovement::where('product_id', $product->id)
            ->orderBy('id')
            ->pluck('balance_after')
            ->all();

        $this->assertEquals([6, 12], $balances);
    }
}
