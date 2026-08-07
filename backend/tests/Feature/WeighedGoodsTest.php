<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Unit;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Loose goods — the half of a Pakistani grocery that comes out of a sack.
 *
 * The shopkeeper quotes "Rs 250 per kilo" and the database holds Rs 0.25 per
 * gram, so almost every test here is really asking the same question: did the
 * figure the shopkeeper typed survive the round trip to the figure the customer
 * is charged?
 */
class WeighedGoodsTest extends TestCase
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

    /** Daal Chana at Rs 250/kg with 20 kg in the sack. */
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
            'low_stock_threshold' => 2000,
            'is_active' => true,
        ], $attributes));
    }

    /* ---------------------------------------------------------- catalogue */

    public function test_a_price_quoted_per_kilo_is_stored_per_gram(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Atta',
                'unit_type' => 'weight',
                'price_unit' => 'kg',
                'price' => 250,
                'cost' => 180,
                'stock_quantity' => 50,
                'low_stock_threshold' => 5,
            ])
            ->assertCreated()
            // What the shopkeeper typed is what the shopkeeper is shown back.
            ->assertJsonPath('product.display_price', 250)
            ->assertJsonPath('product.unit_price_label', 'Rs 250 / kg')
            ->assertJsonPath('product.stock_label', '50 kg');

        $product = Product::query()->where('name', 'Atta')->firstOrFail();

        $this->assertEquals(0.25, $product->price);
        $this->assertEquals(0.18, $product->cost);
        // 50 kg of atta is 50,000 g on the shelf.
        $this->assertEquals(50000, $product->stock_quantity);
        $this->assertEquals(5000, $product->low_stock_threshold);
    }

    /**
     * The reason per-base prices carry four decimal places. Namak at Rs 40/kg
     * is Rs 0.04/g; at two places that rounds to zero and the shop gives its
     * salt away.
     */
    public function test_a_cheap_per_gram_price_survives_the_conversion(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Namak',
                'unit_type' => 'weight',
                'price_unit' => 'kg',
                'price' => 40,
                'stock_quantity' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('product.display_price', 40);

        $this->assertEquals(0.04, Product::query()->where('name', 'Namak')->value('price'));
    }

    public function test_a_price_unit_that_does_not_match_the_goods_is_rejected(): void
    {
        $user = $this->seller();

        // Litres of daal is a data-entry slip, not a business decision.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Daal',
                'unit_type' => 'weight',
                'price_unit' => 'l',
                'price' => 250,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_unit');
    }

    public function test_an_existing_product_still_reads_as_counted(): void
    {
        $user = $this->seller();
        $product = Product::create([
            'user_id' => $user->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'stock_quantity' => 10,
        ]);

        // Nothing was migrated by value: a product created without unit fields
        // is sold by the piece, and Rs 120 "each" was already a per-base price.
        $this->assertSame('each', $product->unit_type);
        $this->assertSame('pc', $product->price_unit);
        $this->assertEquals(120, $product->price);
        $this->assertSame('10', $product->quantityLabel(10));
    }

    /* --------------------------------------------------------------- sale */

    public function test_selling_a_quarter_kilo_charges_the_quarter_kilo_price(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                // "Ek pao daal" — 250 g, sent in base units.
                'items' => [['product_id' => $product->id, 'quantity' => 250]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            // Rs 62.50 on the arithmetic, Rs 63 over the counter — there is no
            // half-rupee coin to hand back, so the ticket settles whole and the
            // 50 paisa is recorded rather than lost.
            ->assertJsonPath('sale.total', 63)
            ->assertJsonPath('sale.rounding_adjustment', 0.5)
            ->assertJsonPath('sale.items.0.quantity_label', '250 g')
            ->assertJsonPath('sale.items.0.unit_price_label', 'Rs 250 / kg');

        // The sack is 250 g lighter, not one unit lighter.
        $this->assertEquals(19750, $product->fresh()->stock_quantity);
    }

    /**
     * The other direction. A shop that only ever rounded up would be quietly
     * overcharging on half its weighed sales, which is the sort of thing a
     * regular customer notices long before the shopkeeper does.
     */
    public function test_a_ticket_below_half_a_rupee_rounds_down(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                // 333 g at Rs 250/kg is Rs 83.25.
                'items' => [['product_id' => $product->id, 'quantity' => 333]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 83)
            ->assertJsonPath('sale.rounding_adjustment', -0.25);
    }

    public function test_change_is_worked_out_from_the_rounded_total(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        // Rs 62.50 rounded to Rs 63, so a hundred-rupee note comes back Rs 37 —
        // never Rs 37.50, which the drawer could not produce.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 250]],
                'payment_method' => 'cash',
                'amount_tendered' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 63)
            ->assertJsonPath('sale.change_due', 37);
    }

    /**
     * The other direction, and the more common one over the counter: the
     * customer names the money and the till works the weight back. Rs 50 of
     * daal at Rs 250/kg is exactly 200 g, and the ticket must say Rs 50.
     */
    public function test_a_sale_entered_by_amount_charges_exactly_that_amount(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 200]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 50)
            ->assertJsonPath('sale.items.0.quantity_label', '200 g');
    }

    public function test_a_fractional_weight_is_kept_to_the_gram(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1750]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 438)
            ->assertJsonPath('sale.items.0.quantity_label', '1.75 kg');

        $this->assertEquals(18250, $product->fresh()->stock_quantity);
    }

    public function test_selling_more_than_the_sack_holds_is_refused_in_kilos(): void
    {
        $user = $this->seller();
        $product = $this->daal($user, ['stock_quantity' => 1500]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 2000]],
            ])
            ->assertStatus(422)
            // A cashier reads kilos, never grams-as-a-bare-number.
            ->assertJsonFragment(['items' => ['Daal Chana: only 1.5 kg left in stock.']]);
    }

    public function test_a_quantity_of_zero_is_refused(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 0]],
            ])
            ->assertStatus(422);
    }

    public function test_the_same_loose_product_twice_on_a_ticket_is_merged(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 250],
                    ['product_id' => $product->id, 'quantity' => 500],
                ],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 188)
            ->assertJsonCount(1, 'sale.items')
            ->assertJsonPath('sale.items.0.quantity_label', '750 g');
    }

    /* ------------------------------------------------------------- profit */

    public function test_margin_on_a_weighed_line_uses_the_weighed_cost(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1000]],
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        // A kilo bought at Rs 180 and sold at Rs 250 makes Rs 70, and the
        // per-gram arithmetic has to land on exactly that.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonPath('sales.0.profit.amount', 70);
    }

    /* ------------------------------------------------------------- refund */

    public function test_a_partial_weight_can_be_handed_back(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $sale = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1000]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->json('sale');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund", [
                'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 250]],
            ])
            ->assertOk()
            ->assertJsonPath('sale.status', 'partially_refunded');

        // 250 g back on the shelf, 750 g still sold.
        $this->assertEquals(19250, $product->fresh()->stock_quantity);
    }

    /**
     * Regression: `remainingQuantity()` returns a float now, and the
     * fully-refunded check used `=== 0`. Against `0.0` that is false in PHP, so
     * every refund — including a whole one — filed as partial and the sale
     * never reached `refunded`.
     */
    public function test_refunding_the_whole_weight_closes_the_sale(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $sale = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 250]],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->json('sale');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund")
            ->assertOk()
            ->assertJsonPath('sale.status', 'refunded');

        $this->assertEquals(20000, $product->fresh()->stock_quantity);
        $this->assertEquals(63, Sale::query()->find($sale['id'])->refunded_amount);
    }

    /* -------------------------------------------------------------- stock */

    public function test_stock_is_topped_up_in_the_unit_the_shop_buys_in(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        // A 50 kg sack arrived from the wholesaler.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => 50,
                'type' => 'restock',
            ])
            ->assertOk()
            ->assertJsonPath('product.stock_label', '70 kg');

        $this->assertEquals(70000, $product->fresh()->stock_quantity);
    }

    public function test_a_half_kilo_can_be_written_off(): void
    {
        $user = $this->seller();
        $product = $this->daal($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -0.5,
                'type' => 'adjustment',
                // Stock coming off has to say why now — see WastageTest.
                'reason' => 'damaged',
            ])
            ->assertOk();

        $this->assertEquals(19500, $product->fresh()->stock_quantity);
    }

    /* --------------------------------------------------------------- unit */

    public function test_volume_goods_read_in_litres(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Cooking Oil',
                'unit_type' => 'volume',
                'price_unit' => 'l',
                'price' => 600,
                'stock_quantity' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('product.unit_price_label', 'Rs 600 / L')
            ->assertJsonPath('product.stock_label', '15 L');

        $this->assertEquals(0.6, Product::query()->where('name', 'Cooking Oil')->value('price'));
    }

    public function test_goods_quoted_by_the_darjan_are_counted_in_pieces(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Anday',
                'unit_type' => 'each',
                'price_unit' => 'dozen',
                'price' => 360,
                'stock_quantity' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('product.unit_price_label', 'Rs 360 / dz');

        $eggs = Product::query()->where('name', 'Anday')->firstOrFail();

        // Quoted by the dozen, sold one at a time.
        $this->assertEquals(30, $eggs->price);
        $this->assertEquals(120, $eggs->stock_quantity);
    }
}
