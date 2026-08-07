<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Stock in — the delivery van at the back door.
 *
 * This is where `products.cost` comes from. Before it existed the cost was
 * whatever someone last typed into the catalogue by hand, so `unit_cost` on a
 * sale line was usually null and every profit figure in the app read near zero.
 *
 * The figure that matters most here is the weighted average: ghee and atta move
 * in price every month, and a shop is almost never selling only the newest
 * sack, so a cost that simply took the latest invoice would misstate the margin
 * in whichever direction the market had just gone.
 */
class PurchaseTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    private function shopkeeper(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /** Atta at Rs 250/kg, quoted by the kilo and stored by the gram. */
    private function atta(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Atta',
            'unit_type' => Unit::TYPE_WEIGHT,
            'base_unit' => 'g',
            'price_unit' => 'kg',
            'price' => 0.25,
            'cost' => null,
            'track_stock' => true,
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  list<array{product_id: int, quantity: float, unit_cost: float}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function purchasePayload(array $items, array $extra = []): array
    {
        return array_merge(['items' => $items], $extra);
    }

    /* ----------------------------------------------------------- receiving */

    public function test_a_purchase_raises_stock_and_leaves_a_restock_movement(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user, ['stock_quantity' => 20000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                // A 50 kg sack at Rs 180 the kilo.
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertCreated()
            ->assertJsonPath('purchase.reference', 'P-000001')
            ->assertJsonPath('purchase.total', 9000)
            ->assertJsonPath('purchase.items.0.quantity_label', '50 kg')
            ->assertJsonPath('purchase.items.0.unit_cost_label', 'Rs 180 / kg');

        // 20 kg was already on the shelf; 50 kg arrived.
        $this->assertEquals(70000, $product->fresh()->stock_quantity);

        $movement = StockMovement::query()->where('product_id', $product->id)->firstOrFail();

        // Stock never moves outside this audited path, so the delivery has to
        // leave a row behind exactly like a sale or a refund does.
        $this->assertSame('restock', $movement->type);
        $this->assertEquals(50000, $movement->quantity_delta);
        $this->assertEquals(70000, $movement->balance_after);
        $this->assertSame('Stock in P-000001', $movement->note);
    }

    public function test_a_quantity_bought_in_kilos_lands_on_the_shelf_as_grams(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 12.5, 'unit_cost' => 180],
            ]))
            ->assertCreated()
            // Rs 180/kg × 12.5 kg = Rs 2,250, and the arithmetic underneath is
            // Rs 0.18/g × 12,500 g.
            ->assertJsonPath('purchase.total', 2250)
            ->assertJsonPath('purchase.items.0.quantity', 12500)
            ->assertJsonPath('purchase.items.0.unit_cost', 0.18)
            ->assertJsonPath('purchase.items.0.display_quantity', 12.5)
            ->assertJsonPath('purchase.items.0.display_unit_cost', 180);

        $this->assertEquals(12500, $product->fresh()->stock_quantity);
    }

    /* --------------------------------------------------------------- cost */

    /**
     * The point of the whole feature. Ten kilos bought at Rs 180 and ten more at
     * Rs 220 leaves twenty kilos that cost the shop Rs 200 a kilo — not Rs 220,
     * which is what a plain overwrite would have claimed for the older half.
     */
    public function test_a_second_delivery_blends_into_a_weighted_average_cost(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 180],
            ]))
            ->assertCreated();

        $this->assertEquals(0.18, $product->fresh()->cost);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 220],
            ]))
            ->assertCreated();

        $product->refresh();

        // Rs 0.20 per gram is Rs 200 per kilo.
        $this->assertEquals(0.2, $product->cost);
        $this->assertEquals(200, $product->displayCost());
        $this->assertEquals(20000, $product->stock_quantity);
    }

    public function test_two_lines_for_one_product_average_against_each_other(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        // The same delivery note listing the item twice — old sacks and new
        // sacks at different rates. The second line must average against the
        // first, not against the empty shelf both started from.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 180],
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 220],
            ]))
            ->assertCreated()
            ->assertJsonPath('purchase.total', 4000);

        $product->refresh();

        $this->assertEquals(0.2, $product->cost);
        $this->assertEquals(20000, $product->stock_quantity);
    }

    /**
     * With an empty shelf there is no older stock whose cost deserves
     * protecting, so a stale figure from months ago is simply replaced.
     */
    public function test_the_new_cost_replaces_the_old_one_when_the_shelf_was_empty(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user, ['cost' => 0.1, 'stock_quantity' => 0]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 200],
            ]))
            ->assertCreated();

        $product->refresh();

        // Not the Rs 155/kg a blend against the phantom old stock would give.
        $this->assertEquals(0.2, $product->cost);
        $this->assertEquals(200, $product->displayCost());
    }

    /**
     * Counted goods go through the same arithmetic, only with a factor of one
     * underneath — a product bought by the piece has no conversion to lose.
     */
    public function test_counted_goods_average_the_same_way(): void
    {
        $user = $this->shopkeeper();
        $product = Product::create([
            'user_id' => $user->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'cost' => 90,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 30, 'unit_cost' => 100],
            ]))
            ->assertCreated()
            ->assertJsonPath('purchase.total', 3000);

        // (10 × 90 + 30 × 100) / 40 = 97.5
        $this->assertEquals(97.5, $product->fresh()->cost);
        $this->assertEquals(40, $product->fresh()->stock_quantity);
    }

    /** The recorded cost is what makes a sale's margin knowable at all. */
    public function test_a_sale_after_a_purchase_reports_a_real_margin(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1000]],
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        // A kilo bought at Rs 180 and sold at Rs 250 makes Rs 70 — and the
        // figure is complete, which it never was before stock in existed.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonPath('sales.0.profit.amount', 70)
            ->assertJsonPath('sales.0.profit.is_complete', true);
    }

    /* ------------------------------------------------------------- payment */

    public function test_a_part_paid_delivery_records_what_is_still_owed(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ], ['amount_paid' => 4000, 'invoice_number' => 'INV-77', 'discount_amount' => 500]))
            ->assertCreated()
            ->assertJsonPath('purchase.total', 8500)
            ->assertJsonPath('purchase.outstanding_amount', 4500)
            ->assertJsonPath('purchase.payment_status', 'partial')
            ->assertJsonPath('purchase.invoice_number', 'INV-77')
            // The money handed to the driver is the first instalment, not a
            // bare figure on the invoice — PurchasePaymentTest carries the rest.
            ->assertJsonPath('purchase.payments.0.amount', 4000)
            ->assertJsonPath('purchase.payments.0.balance_after', 4500);
    }

    public function test_paying_more_than_the_bill_is_refused(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 180],
            ], ['amount_paid' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_paid');
    }

    public function test_a_discount_larger_than_the_bill_is_refused(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 180],
            ], ['discount_amount' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_amount');
    }

    /* ----------------------------------------------------------- suppliers */

    public function test_a_supplier_can_be_created_and_attached_to_a_purchase(): void
    {
        $user = $this->shopkeeper();
        $product = $this->atta($user);

        $supplierId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/suppliers', ['name' => 'Karachi Flour Mills', 'phone' => '0300-1234567'])
            ->assertCreated()
            ->json('supplier.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ], ['supplier_id' => $supplierId]))
            ->assertCreated()
            ->assertJsonPath('purchase.supplier.name', 'Karachi Flour Mills');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/purchases')
            ->assertOk()
            ->assertJsonPath('purchases.0.supplier.name', 'Karachi Flour Mills')
            ->assertJsonPath('purchases.0.items_count', 1);
    }

    public function test_the_same_supplier_name_cannot_be_added_twice(): void
    {
        $user = $this->shopkeeper();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/suppliers', ['name' => 'Karachi Flour Mills'])
            ->assertCreated();

        // Two rows for one wholesaler split a running balance in half, and the
        // shopkeeper has no way to tell which of the two is the real one.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/suppliers', ['name' => 'Karachi Flour Mills'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_supplier_can_be_renamed(): void
    {
        $user = $this->shopkeeper();
        $supplier = Supplier::create(['user_id' => $user->id, 'name' => 'Flour Mills']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/suppliers/{$supplier->id}", ['name' => 'Karachi Flour Mills', 'phone' => '0300-1111111'])
            ->assertOk()
            ->assertJsonPath('supplier.name', 'Karachi Flour Mills');
    }

    /* ---------------------------------------------------------- rejections */

    public function test_a_purchase_for_an_unknown_product_is_refused(): void
    {
        $user = $this->shopkeeper();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => 99999, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Purchase::query()->count());
    }

    public function test_a_purchase_with_no_lines_is_refused(): void
    {
        $user = $this->shopkeeper();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    /* ---------------------------------------------------------- ownership */

    public function test_one_shop_cannot_book_stock_into_another_shops_product(): void
    {
        $mine = $this->shopkeeper();
        $theirs = $this->shopkeeper();
        $product = $this->atta($theirs, ['stock_quantity' => 20000]);

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        // Nothing landed on the neighbour's shelf, and no cost was rewritten.
        $this->assertEquals(20000, $product->fresh()->stock_quantity);
        $this->assertNull($product->fresh()->cost);
    }

    public function test_one_shop_cannot_read_another_shops_purchases(): void
    {
        $mine = $this->shopkeeper();
        $theirs = $this->shopkeeper();
        $product = $this->atta($theirs);

        $purchaseId = $this->actingAs($theirs, 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertCreated()
            ->json('purchase.id');

        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/purchases/{$purchaseId}")
            ->assertStatus(403);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/purchases')
            ->assertOk()
            ->assertJsonCount(0, 'purchases');
    }

    public function test_one_shop_cannot_rename_another_shops_supplier(): void
    {
        $mine = $this->shopkeeper();
        $theirs = $this->shopkeeper();
        $supplier = Supplier::create(['user_id' => $theirs->id, 'name' => 'Karachi Flour Mills']);

        $this->actingAs($mine, 'sanctum')
            ->putJson("/api/suppliers/{$supplier->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Karachi Flour Mills', $supplier->fresh()->name);
    }

    /**
     * Booking a delivery in reprices the catalogue, so it is gated by the same
     * permission that guards the products screen rather than by the till.
     */
    public function test_staff_without_the_catalogue_permission_cannot_book_stock_in(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $product = $this->atta($owner);

        $cashier = User::factory()->create();
        $this->subscribeUser($cashier);
        $cashier->assignRole(User::ROLE_STAFF, $shop->id);
        $cashier->setProductPermission(false);

        $this->actingAs($cashier->fresh(), 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertStatus(403);
    }

    public function test_staff_with_the_catalogue_permission_book_into_the_shops_stock(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $product = $this->atta($owner);

        $clerk = User::factory()->create();
        $this->subscribeUser($clerk);
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(true);

        $this->actingAs($clerk->fresh(), 'sanctum')
            ->postJson('/api/purchases', $this->purchasePayload([
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 180],
            ]))
            ->assertCreated();

        // Filed under the shop owner, not the clerk who signed for the van.
        $this->assertSame($owner->id, Purchase::query()->firstOrFail()->user_id);
        $this->assertEquals(50000, $product->fresh()->stock_quantity);
    }
}
