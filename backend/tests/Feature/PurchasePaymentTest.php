<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Paying a supplier's invoice off in instalments — the mirror of the khata.
 *
 * A kiryana shop takes a Rs 40,000 delivery, hands the driver Rs 15,000 and
 * clears the rest across the month. Before `purchase_payments` existed the only
 * way to record that was to type each new figure over the last one, so the shop
 * kept the balance and lost every payment that produced it.
 *
 * The invariant every test below leans on: `purchases.amount_paid` is never
 * incremented, it is re-derived from the payment rows. A total that is added to
 * drifts the first time anything goes wrong and can never be pulled back.
 */
class PurchasePaymentTest extends TestCase
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
    private function atta(User $user): Product
    {
        return Product::create([
            'user_id' => $user->id,
            'name' => 'Atta',
            'unit_type' => Unit::TYPE_WEIGHT,
            'base_unit' => 'g',
            'price_unit' => 'kg',
            'price' => 0.25,
            'track_stock' => true,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * A Rs 40,000 delivery — 200 kg of atta at Rs 200 the kilo — which is the
     * shape of bill this whole feature exists for.
     */
    private function delivery(User $user, array $extra = []): array
    {
        $product = $this->atta($user);

        $purchase = $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', array_merge([
                'items' => [['product_id' => $product->id, 'quantity' => 200, 'unit_cost' => 200]],
            ], $extra))
            ->assertCreated()
            ->json('purchase');

        return $purchase;
    }

    /** What the invoice's own payment rows add up to, which is the only truth. */
    private function paidFromHistory(int $purchaseId): float
    {
        return round((float) PurchasePayment::query()->where('purchase_id', $purchaseId)->sum('amount'), 2);
    }

    /* ------------------------------------------------------------- deposit */

    public function test_a_deposit_at_creation_becomes_the_first_payment_row(): void
    {
        $user = $this->shopkeeper();

        $purchase = $this->delivery($user, ['amount_paid' => 15000, 'paid_by_name' => 'Bilal']);

        $this->assertEquals(40000, $purchase['total']);
        $this->assertEquals(15000, $purchase['amount_paid']);
        $this->assertSame('partial', $purchase['payment_status']);

        // Without this row `amount_paid` would be a figure with nothing behind
        // it, and the history would open at the second payment.
        $deposit = PurchasePayment::query()->where('purchase_id', $purchase['id'])->firstOrFail();

        $this->assertEquals(15000, $deposit->amount);
        $this->assertSame('cash', $deposit->method);
        $this->assertSame('Bilal', $deposit->paid_by_name);
        $this->assertSame($user->id, $deposit->recorded_by);
        $this->assertSame(15000.0, $this->paidFromHistory($purchase['id']));
    }

    public function test_a_delivery_paid_for_at_the_door_records_the_method_it_was_paid_by(): void
    {
        $user = $this->shopkeeper();

        $purchase = $this->delivery($user, ['amount_paid' => 40000, 'deposit_method' => 'bank_transfer']);

        $this->assertSame('paid', $purchase['payment_status']);
        $this->assertEquals(0, $purchase['outstanding_amount']);
        $this->assertSame('bank_transfer', PurchasePayment::query()->firstOrFail()->method);
    }

    public function test_an_unpaid_delivery_starts_with_no_payment_rows(): void
    {
        $user = $this->shopkeeper();

        $purchase = $this->delivery($user);

        $this->assertSame('unpaid', $purchase['payment_status']);
        $this->assertEquals(0, $purchase['amount_paid']);
        $this->assertSame(0, PurchasePayment::query()->where('purchase_id', $purchase['id'])->count());
    }

    /* ---------------------------------------------------------- instalments */

    public function test_a_partial_payment_moves_the_invoice_to_partial(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", [
                'amount' => 15000,
                'method' => 'cash',
                'paid_by_name' => 'Bilal',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Payment recorded.')
            ->assertJsonPath('purchase.amount_paid', 15000)
            ->assertJsonPath('purchase.outstanding_amount', 25000)
            ->assertJsonPath('purchase.payment_status', 'partial')
            ->assertJsonPath('purchase.payments.0.paid_by_name', 'Bilal')
            // The balance beside a row is what was still owed after it, which
            // is the figure the supplier's ledger will show.
            ->assertJsonPath('purchase.payments.0.balance_after', 25000);

        $this->assertSame(15000.0, $this->paidFromHistory($purchase['id']));
    }

    public function test_a_second_payment_clears_the_invoice_to_paid(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user, ['amount_paid' => 15000]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 10000, 'method' => 'easypaisa', 'reference' => 'EP-99'])
            ->assertOk()
            ->assertJsonPath('purchase.payment_status', 'partial')
            ->assertJsonPath('purchase.outstanding_amount', 15000);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 15000, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('message', 'Payment recorded. This invoice is settled in full.')
            ->assertJsonPath('purchase.payment_status', 'paid')
            ->assertJsonPath('purchase.outstanding_amount', 0)
            ->assertJsonPath('purchase.amount_paid', 40000);

        // Three rows: the deposit at the door and the two later instalments.
        $this->assertSame(3, PurchasePayment::query()->where('purchase_id', $purchase['id'])->count());
        $this->assertSame(40000.0, $this->paidFromHistory($purchase['id']));
    }

    /**
     * The whole point of recomputing rather than incrementing: whatever the
     * history says is what the invoice says, at every step.
     */
    public function test_amount_paid_always_equals_the_sum_of_the_payment_rows(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user, ['amount_paid' => 5000]);

        foreach ([2500, 7500.25, 1000.75, 4000] as $amount) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => $amount, 'method' => 'cash'])
                ->assertOk();

            $fresh = Purchase::query()->findOrFail($purchase['id']);

            $this->assertSame($this->paidFromHistory($purchase['id']), round((float) $fresh->amount_paid, 2));
            $this->assertSame($fresh->resolvePaymentStatus(), $fresh->payment_status);
        }

        $this->assertSame(20001.0, $this->paidFromHistory($purchase['id']));
    }

    public function test_the_history_reads_back_in_the_order_the_money_moved(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user, ['amount_paid' => 15000]);

        // Two instalments in the same second — `paid_at` alone cannot separate
        // them, so the running balance would be printed against the wrong rows.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 5000, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 10000, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/purchases/{$purchase['id']}")
            ->assertOk()
            ->assertJsonCount(3, 'purchase.payments')
            ->assertJsonPath('purchase.payments.0.amount', 15000)
            ->assertJsonPath('purchase.payments.0.balance_after', 25000)
            ->assertJsonPath('purchase.payments.1.amount', 5000)
            ->assertJsonPath('purchase.payments.1.balance_after', 20000)
            ->assertJsonPath('purchase.payments.2.amount', 10000)
            ->assertJsonPath('purchase.payments.2.balance_after', 10000);
    }

    /* ---------------------------------------------------------- rejections */

    public function test_paying_more_than_is_outstanding_is_refused(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user, ['amount_paid' => 15000]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 25000.01, 'method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        // Nothing was written, so the invoice still owes exactly what it did.
        $this->assertSame(15000.0, $this->paidFromHistory($purchase['id']));
        $this->assertEquals(15000, Purchase::query()->findOrFail($purchase['id'])->amount_paid);
    }

    public function test_paying_a_settled_invoice_is_refused(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user, ['amount_paid' => 40000]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(1, PurchasePayment::query()->where('purchase_id', $purchase['id'])->count());
    }

    public function test_a_zero_payment_is_refused(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 0, 'method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    /** Paying a bill with more credit moves no money, so the method is not offered. */
    public function test_credit_is_not_a_way_to_pay_a_supplier(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1000, 'method' => 'credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    /* --------------------------------------------------------- what I owe */

    public function test_the_outstanding_list_reports_what_the_shop_owes_its_suppliers(): void
    {
        $user = $this->shopkeeper();
        $mills = Supplier::create(['user_id' => $user->id, 'name' => 'Karachi Flour Mills']);

        $partPaid = $this->delivery($user, ['amount_paid' => 15000, 'supplier_id' => $mills->id]);
        $unpaid = $this->delivery($user);
        $settled = $this->delivery($user, ['amount_paid' => 40000]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/purchases/outstanding')
            ->assertOk()
            // Rs 25,000 still owed on the first plus Rs 40,000 on the second.
            ->assertJsonPath('total_outstanding', 65000)
            ->assertJsonPath('invoices_count', 2)
            ->assertJsonCount(2, 'purchases');

        // Oldest first — that is the invoice the supplier will ask about.
        $response->assertJsonPath('purchases.0.id', $partPaid['id']);
        $response->assertJsonPath('purchases.0.outstanding_amount', 25000);
        $response->assertJsonPath('purchases.1.id', $unpaid['id']);

        $ids = collect($response->json('purchases'))->pluck('id');
        $this->assertFalse($ids->contains($settled['id']), 'A settled invoice is not a payable.');

        // Broken down by wholesaler, biggest debt first, so the owner knows who
        // to go and see rather than only how much is out in total.
        $response->assertJsonPath('suppliers.0.name', 'No supplier');
        $response->assertJsonPath('suppliers.0.outstanding', 40000);
        $response->assertJsonPath('suppliers.1.name', 'Karachi Flour Mills');
        $response->assertJsonPath('suppliers.1.outstanding', 25000);
    }

    public function test_the_outstanding_list_empties_as_invoices_are_cleared(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 40000, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/purchases/outstanding')
            ->assertOk()
            ->assertJsonPath('total_outstanding', 0)
            ->assertJsonCount(0, 'purchases');
    }

    /* ---------------------------------------------------------- ownership */

    public function test_one_shop_cannot_pay_another_shops_invoice(): void
    {
        $mine = $this->shopkeeper();
        $theirs = $this->shopkeeper();

        $purchase = $this->delivery($theirs);

        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1000, 'method' => 'cash'])
            ->assertStatus(403);

        $this->assertSame(0, PurchasePayment::query()->count());
        $this->assertEquals(0, Purchase::query()->findOrFail($purchase['id'])->amount_paid);
    }

    public function test_one_shop_cannot_see_another_shops_payables(): void
    {
        $mine = $this->shopkeeper();
        $theirs = $this->shopkeeper();

        $this->delivery($theirs, ['amount_paid' => 15000]);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/purchases/outstanding')
            ->assertOk()
            ->assertJsonPath('total_outstanding', 0)
            ->assertJsonCount(0, 'purchases');
    }

    /**
     * Settling the bill is the other half of receiving the goods, so it is
     * gated by the same catalogue permission rather than by the till.
     */
    public function test_staff_without_the_catalogue_permission_cannot_pay_a_supplier(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $purchase = $this->delivery($owner);

        $cashier = User::factory()->create();
        $this->subscribeUser($cashier);
        $cashier->assignRole(User::ROLE_STAFF, $shop->id);
        $cashier->setProductPermission(false);

        $this->actingAs($cashier->fresh(), 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1000, 'method' => 'cash'])
            ->assertStatus(403);
    }

    public function test_a_clerk_pays_against_the_shop_owners_invoice(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $purchase = $this->delivery($owner);

        $clerk = User::factory()->create();
        $this->subscribeUser($clerk);
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(true);

        $this->actingAs($clerk->fresh(), 'sanctum')
            ->postJson("/api/purchases/{$purchase['id']}/payments", [
                'amount' => 5000,
                'method' => 'cash',
                // The clerk typed it; the delivery boy handed the money over.
                'paid_by_name' => 'Imran',
            ])
            ->assertOk()
            ->assertJsonPath('purchase.amount_paid', 5000);

        $payment = PurchasePayment::query()->firstOrFail();

        $this->assertSame($clerk->id, $payment->recorded_by);
        $this->assertSame('Imran', $payment->paid_by_name);
        $this->assertSame($owner->id, Purchase::query()->findOrFail($purchase['id'])->user_id);
    }
}
