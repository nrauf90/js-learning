<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Unit;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * What the shop loses without selling it.
 *
 * Sabzi wilts, doodh turns, a peti gets dropped and a packet walks out of the
 * door. Every one of those is money, and until stock could only be nudged with
 * a free-text note none of it reached the P&L. So the questions here are always
 * the same two: was the shopkeeper made to say *why*, and did the till work out
 * what it was worth in rupees?
 */
class WastageTest extends TestCase
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

    /** Bread: 20 loaves on the rack, bought at Rs 80, sold at Rs 120. */
    private function bread(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Double Roti',
            'price' => 120,
            'cost' => 80,
            'track_stock' => true,
            'stock_quantity' => 20,
        ], $attributes));
    }

    /** Daal Chana at Rs 250/kg costing Rs 180/kg, with 20 kg in the sack. */
    private function daal(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Daal Chana',
            'unit_type' => Unit::TYPE_WEIGHT,
            'base_unit' => 'g',
            'price_unit' => 'kg',
            'price' => 0.25,
            'cost' => 0.18,
            'track_stock' => true,
            'stock_quantity' => 20000,
        ], $attributes));
    }

    /* ------------------------------------------------------------ wastage */

    public function test_stock_cannot_be_written_off_without_a_reason(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -3,
                'type' => 'adjustment',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Nothing moved, and nothing was filed — a refused write-off must not
        // leave half a ledger entry behind.
        $this->assertEquals(20, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_reason_outside_the_list_is_refused(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        // Free text is exactly what the typed list exists to stop: "chuhon ne
        // khaya" cannot be added up at the end of the month.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -3,
                'type' => 'adjustment',
                'reason' => 'rats',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertEquals(20, $product->fresh()->stock_quantity);
    }

    public function test_stale_bread_is_written_off_at_what_it_cost(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -3,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_EXPIRED,
                'note' => 'Kal ki roti',
            ])
            ->assertOk()
            ->assertJsonPath('product.stock_quantity', 17);

        // Three loaves bought at Rs 80 is Rs 240 gone — at cost, not at the
        // Rs 120 they would have fetched. The shop never had that margin.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'reason' => 'expired',
            'quantity_delta' => -3,
            'balance_after' => 17,
            'cost_value' => 240,
            'note' => 'Kal ki roti',
        ]);
    }

    /**
     * The per-gram case, which is where a wastage figure is easiest to get
     * wrong: half a kilo of daal is 500 g at Rs 0.18/g, and reading the cost as
     * "Rs 180" against a delta of 500 would book a Rs 90,000 loss.
     */
    public function test_spoiled_loose_goods_are_valued_per_gram(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                // Typed in the unit the shop quotes in — half a kilo.
                'quantity_delta' => -0.5,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_SPOILED,
            ])
            ->assertOk()
            ->assertJsonPath('product.stock_label', '19.5 kg');

        $movement = StockMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();

        $this->assertEquals(-500, $movement->quantity_delta);
        $this->assertEquals(90, $movement->cost_value);
        $this->assertTrue($movement->isWastage());
    }

    public function test_a_product_with_no_cost_records_no_money_lost(): void
    {
        $user = $this->seller();
        $product = $this->bread($user, ['cost' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -2,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_DAMAGED,
            ])
            ->assertOk();

        // Null, never zero: the shop does not know what those two loaves cost,
        // and a zero here would report the loss as free.
        $this->assertNull(
            StockMovement::query()->where('product_id', $product->id)->latest('id')->value('cost_value')
        );
    }

    public function test_a_delivery_needs_no_excuse(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => 12,
                'type' => 'restock',
            ])
            ->assertOk()
            ->assertJsonPath('product.stock_quantity', 32);

        $movement = StockMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();

        $this->assertNull($movement->reason);
        // Still priced: what came in is worth counting too, and Rs 80 × 12 is
        // the same arithmetic in the other direction.
        $this->assertEquals(960, $movement->cost_value);
    }

    /**
     * A recount is not wastage. The goods were never on the shelf, so the money
     * was lost when they left — counting it again would charge it twice.
     */
    public function test_a_count_correction_is_not_counted_as_wastage(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -1,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_COUNT_CORRECTION,
            ])
            ->assertOk();

        $movement = StockMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();

        $this->assertSame('count_correction', $movement->reason);
        $this->assertFalse($movement->isWastage());
    }

    public function test_one_shop_cannot_write_off_another_shops_stock(): void
    {
        $owner = $this->seller();
        $stranger = $this->seller();
        $product = $this->bread($owner);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -5,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_THEFT,
            ])
            ->assertForbidden();

        $this->assertEquals(20, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /* ------------------------------------------------------------- expiry */

    public function test_an_expiry_date_round_trips_and_reads_as_expiring(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Doodh 1L',
                'unit_type' => 'volume',
                'price_unit' => 'l',
                'price' => 220,
                'stock_quantity' => 12,
                'expiry_date' => now()->addDays(3)->toDateString(),
            ])
            ->assertCreated()
            // Typing the date is the request to be warned about it — filing it
            // and then saying nothing is what nobody types a date hoping for.
            ->assertJsonPath('product.track_expiry', true)
            ->assertJsonPath('product.days_to_expiry', 3)
            ->assertJsonPath('product.is_expiring_soon', true)
            ->assertJsonPath('product.is_expired', false);
    }

    /** A packet dated today is good all day today. */
    public function test_stock_dated_today_is_not_yet_expired(): void
    {
        $user = $this->seller();
        $product = $this->bread($user, [
            'expiry_date' => now()->toDateString(),
            'track_expiry' => true,
        ]);

        $this->assertFalse($product->isExpired());
        $this->assertTrue($product->isExpiringSoon());
        $this->assertSame(0, $product->daysToExpiry());

        $product->expiry_date = now()->subDay()->toDateString();

        $this->assertTrue($product->isExpired());
        $this->assertSame(-1, $product->daysToExpiry());
    }

    public function test_the_expiring_filter_lists_what_needs_shifting(): void
    {
        $user = $this->seller();

        $expired = $this->bread($user, [
            'name' => 'Purana Doodh',
            'expiry_date' => now()->subDays(2)->toDateString(),
            'track_expiry' => true,
        ]);
        $soon = $this->bread($user, [
            'name' => 'Anday',
            'expiry_date' => now()->addDays(5)->toDateString(),
            'track_expiry' => true,
        ]);
        // Inside the window by date, but the shop asked not to be told.
        $this->bread($user, [
            'name' => 'Untracked Cheese',
            'expiry_date' => now()->addDays(5)->toDateString(),
            'track_expiry' => false,
        ]);
        $this->bread($user, ['name' => 'Surf Excel']);
        $this->bread($user, [
            'name' => 'Achaar',
            'expiry_date' => now()->addMonths(8)->toDateString(),
            'track_expiry' => true,
        ]);

        $names = $this->actingAs($user, 'sanctum')
            ->getJson('/api/products?expiring=1')
            ->assertOk()
            ->json('products.*.name');

        // Already gone off comes back too — it is the most urgent thing on the
        // shelf, not something to drop off the list for being late.
        $this->assertEqualsCanonicalizing(['Purana Doodh', 'Anday'], $names);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products?expiring=1&expiring_days=3')
            ->assertOk()
            ->assertJsonPath('products.*.name', ['Purana Doodh']);

        $this->assertTrue($expired->fresh()->isExpired());
        $this->assertTrue($soon->fresh()->isExpiringSoon());
    }

    public function test_the_expiring_filter_stays_inside_the_shop(): void
    {
        $owner = $this->seller();
        $stranger = $this->seller();

        $this->bread($owner, [
            'name' => 'Owner Milk',
            'expiry_date' => now()->addDay()->toDateString(),
            'track_expiry' => true,
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/products?expiring=1')
            ->assertOk()
            ->assertJsonCount(0, 'products');
    }

    /* ---------------------------------------------------------- pack size */

    public function test_a_peti_of_twenty_four_survives_the_round_trip(): void
    {
        $user = $this->seller();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Cola 345ml',
                'price' => 80,
                'stock_quantity' => 48,
                'pack_size' => 24,
                'pack_label' => 'Peti',
            ])
            ->assertCreated()
            ->assertJsonPath('product.pack_size', 24)
            ->assertJsonPath('product.pack_label_text', '1 Peti = 24 pc');

        // Bought by the peti, sold one bottle at a time: the pack changes
        // nothing about the price the customer pays for a single.
        $this->assertEquals(80, $response->json('product.display_price'));
        $this->assertEquals(24, Product::query()->where('name', 'Cola 345ml')->value('pack_size'));
    }

    /**
     * The pack is typed in the unit the shop quotes in, like every other
     * quantity on the form. A bori quoted per kilo holds 50 kg, which is 50,000
     * g underneath — the same column as the peti's 24 pieces.
     */
    public function test_a_bori_quoted_per_kilo_is_stored_in_grams(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Atta',
                'unit_type' => 'weight',
                'price_unit' => 'kg',
                'price' => 120,
                'stock_quantity' => 100,
                'pack_size' => 50,
                'pack_label' => 'Bori',
            ])
            ->assertCreated()
            ->assertJsonPath('product.pack_size', 50000)
            ->assertJsonPath('product.display_pack_size', 50)
            ->assertJsonPath('product.pack_label_text', '1 Bori = 50 kg');
    }

    public function test_a_product_without_a_pack_says_nothing_about_one(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertOk()
            // Default 1: bought the way it is sold, so "1 Pack = 1 pc" would be
            // a line of noise under most of the catalogue.
            ->assertJsonPath('product.pack_size', 1)
            ->assertJsonPath('product.pack_label_text', null);
    }

    public function test_a_pack_can_be_changed_on_an_existing_product(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", [
                'name' => 'Double Roti',
                'price' => 120,
                'pack_size' => 12,
                'pack_label' => 'Carton',
            ])
            ->assertOk()
            ->assertJsonPath('product.pack_label_text', '1 Carton = 12 pc');

        // Stock stays off the update path however much else moves — it only
        // travels through sales, refunds and the adjust endpoint.
        $this->assertEquals(20, $product->fresh()->stock_quantity);
    }

    public function test_a_pack_of_zero_is_refused(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['pack_size' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pack_size');
    }

    /**
     * `stock_movements` records what moved; `activity_logs` records who moved it.
     * Without the reason on both, the trail answers "Imran adjusted the bread"
     * and leaves the only question worth asking — spoiled, stolen or miscounted
     * — to the one table nobody opens when they are chasing a person.
     */
    public function test_the_reason_reaches_the_activity_trail(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -2,
                'type' => 'adjustment',
                'reason' => StockMovement::REASON_SPOILED,
                'note' => 'Left out overnight',
            ])
            ->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLogger::STOCK_ADJUSTED)->latest('id')->firstOrFail();

        $this->assertSame(StockMovement::REASON_SPOILED, $log->changes['reason']);
        $this->assertSame('Left out overnight', $log->changes['note']);
    }

    public function test_a_delivery_leaves_no_reason_on_the_trail(): void
    {
        $user = $this->seller();
        $product = $this->bread($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => 12,
                'type' => 'restock',
            ])
            ->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLogger::STOCK_ADJUSTED)->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('reason', $log->changes);
    }
}
