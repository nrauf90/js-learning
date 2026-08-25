<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use App\Services\ReceiptImageStore;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Proof of money taken against the khata.
 *
 * The customer transfers Rs 2,000 on JazzCash and shows the screenshot at the
 * counter. Three weeks later they say they paid and the notebook says otherwise.
 * This is the file that settles it.
 *
 * The wrinkle that makes this different from a supplier invoice: a khata payment
 * is spread across the customer's unpaid sales oldest-first, so one handful of
 * notes writes *several* `sale_payments` rows. There is no single "the" payment
 * to hang the picture on, so it goes on the first and the ledger gathers
 * attachments back across the group — which is the same grouping it already uses
 * to show one transfer as one line.
 */
class KhataAttachmentTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
        Storage::fake('receipts');
    }

    /* ---------------------------------------------------------- happy path */

    public function test_a_transfer_screenshot_is_filed_against_a_khata_payment(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);

        $paid = $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customer->id}/payments", [
                'amount' => 100,
                'method' => 'jazzcash',
                'reference' => 'TRX-4471',
                'received_by_name' => 'Bilal',
            ])
            ->assertOk();

        $paymentId = $paid->json('payment_id');
        $this->assertNotNull($paymentId, 'the khata response must name a row to attach the proof to');

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png', 720, 1500),
                'caption' => 'JazzCash TRX-4471',
            ])
            ->assertCreated();

        $attachment = Attachment::sole();

        $this->assertSame(SalePayment::class, $attachment->attachable_type);
        $this->assertSame((int) $paymentId, (int) $attachment->attachable_id);
        $this->assertStringStartsWith(ReceiptImageStore::KHATA_DIR.'/', $attachment->path);
        $this->assertTrue(Storage::disk('receipts')->exists($attachment->path));
        $this->assertSame($user->id, (int) $attachment->user_id);
    }

    public function test_the_ledger_reads_the_screenshot_back_on_the_payment_row(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);

        $paymentId = $this->settle($user, $customer, 100);

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
                'caption' => 'JazzCash TRX-4471',
            ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customer->id}/ledger")
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonCount(1, 'payments.0.attachments')
            ->assertJsonPath('payments.0.attachments.0.caption', 'JazzCash TRX-4471');
    }

    /* ------------------------------------------- the oldest-first allocation */

    /**
     * The case this whole grouping exists for: Rs 300 against two Rs 240 tickets
     * writes two instalment rows, and the ledger shows them as one payment. The
     * screenshot must appear on that one line no matter which row it went on.
     */
    public function test_one_transfer_split_across_two_tickets_shows_its_proof_once(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->onCredit($user, $product, 2);
        $this->onCredit($user, $product, 2);

        $customer = Customer::sole();

        $paid = $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customer->id}/payments", [
                'amount' => 300,
                'method' => 'jazzcash',
            ])
            ->assertOk();

        // Two tickets, so two rows were written.
        $this->assertCount(2, $paid->json('allocations'));
        foreach ($paid->json('allocations') as $allocation) {
            $this->assertNotNull($allocation['payment_id']);
        }

        // The picture goes on the anchor row only — one upload, one file.
        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paid->json('payment_id')}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])->assertCreated();

        $this->assertDatabaseCount('attachments', 1);

        // And the ledger shows one payment carrying one attachment, not two
        // rows one of which is missing it.
        $ledger = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customer->id}/ledger")
            ->assertOk();

        $this->assertCount(1, $ledger->json('payments'));
        $this->assertCount(2, $ledger->json('payments.0.allocations'));
        $this->assertCount(1, $ledger->json('payments.0.attachments'));
    }

    /**
     * The other half of the same guarantee: a picture filed against the *second*
     * allocation still shows on the group. Reading only the representative row's
     * attachments would lose it.
     */
    public function test_proof_filed_against_a_later_allocation_still_shows_on_the_group(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->onCredit($user, $product, 2);
        $this->onCredit($user, $product, 2);

        $customer = Customer::sole();

        $paid = $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customer->id}/payments", [
                'amount' => 300,
                'method' => 'jazzcash',
            ])->assertOk();

        $second = $paid->json('allocations.1.payment_id');

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$second}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customer->id}/ledger")
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonCount(1, 'payments.0.attachments');
    }

    /* ---------------------------------------------- a single credit sale too */

    public function test_an_instalment_against_one_sale_carries_its_own_proof(): void
    {
        $user = $this->seller();
        $product = $this->product($user);
        $sale = $this->onCredit($user, $product, 2);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", [
                'amount' => 100,
                'method' => 'bank_transfer',
                'reference' => 'TRX-88',
            ])->assertOk();

        $payment = SalePayment::where('sale_id', $sale['id'])->sole();

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$payment->id}/attachments", [
                'image' => UploadedFile::fake()->image('slip.png'),
            ])->assertCreated();

        $this->assertSame(1, $payment->attachments()->count());
    }

    /* ------------------------------------------------------- shop boundary */

    public function test_another_shop_cannot_read_your_khata_proof(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);
        $paymentId = $this->settle($user, $customer, 100);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])->json('attachment.id');

        $stranger = $this->seller();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/attachments/{$id}")
            ->assertForbidden();
    }

    public function test_another_shop_cannot_attach_to_your_khata_payment(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);
        $paymentId = $this->settle($user, $customer, 100);

        $stranger = $this->seller();

        $this->actingAs($stranger, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('forged.png'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_a_guest_cannot_read_a_khata_proof(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);
        $paymentId = $this->settle($user, $customer, 100);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])->json('attachment.id');

        // actingAs() stays in force for the rest of the test, so the guard has
        // to be dropped or this would assert nothing at all.
        $this->app->make('auth')->forgetGuards();

        $this->getJson("/api/attachments/{$id}")->assertUnauthorized();
    }

    /* ------------------------------------------------------ staff and roles */

    /**
     * The difference from a supplier invoice, and the reason this endpoint is
     * gated on `settle` rather than the catalogue permission: udhaar is
     * collected by whoever is behind the counter when the customer walks in. A
     * till-only cashier who may take the money must be able to file the proof.
     */
    public function test_a_till_only_staff_account_can_file_khata_proof(): void
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Galla Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $customer = $this->customerOwing($owner, 2);
        $paymentId = $this->settle($owner, $customer, 100);

        $clerk = User::factory()->create();
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(false);

        $this->actingAs($clerk, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])
            ->assertCreated();

        // Filed under the shop, recorded against the clerk.
        $attachment = Attachment::sole();
        $this->assertSame($owner->id, (int) $attachment->user_id);
        $this->assertSame($clerk->id, (int) $attachment->uploaded_by);
    }

    public function test_a_till_only_staff_account_can_remove_khata_proof_it_filed(): void
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Galla Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $customer = $this->customerOwing($owner, 2);
        $paymentId = $this->settle($owner, $customer, 100);

        $clerk = User::factory()->create();
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(false);

        $id = $this->actingAs($clerk, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png'),
            ])->json('attachment.id');

        $this->actingAs($clerk, 'sanctum')
            ->deleteJson("/api/attachments/{$id}")
            ->assertOk();

        $this->assertDatabaseCount('attachments', 0);
    }

    /* ---------------------------------------------- what will not be stored */

    public function test_a_php_payload_wearing_a_png_header_is_rejected(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);
        $paymentId = $this->settle($user, $customer, 100);

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->createWithContent(
                    'proof.png',
                    "\x89PNG\r\n\x1a\n".'<?php echo shell_exec($_GET["c"]); ?>'
                ),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_the_stored_filename_is_not_taken_from_the_upload(): void
    {
        $user = $this->seller();
        $customer = $this->customerOwing($user, 2);
        $paymentId = $this->settle($user, $customer, 100);

        $this->actingAs($user, 'sanctum')
            ->post("/api/sale-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('../../../evil.php.png'),
            ])
            ->assertCreated();

        $path = Attachment::sole()->path;

        $this->assertStringStartsWith(ReceiptImageStore::KHATA_DIR.'/khata-'.$paymentId.'-', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringEndsWith('.png', $path);
    }

    /* ------------------------------------------------------------ fixtures */

    private function seller(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    private function product(User $user): Product
    {
        return Product::create([
            'user_id' => $user->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'cost' => 90,
            'track_stock' => true,
            'stock_quantity' => 500,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function onCredit(User $user, Product $product, float $quantity): array
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
                'payment_method' => 'credit',
                'customer_name' => 'Bilal Traders',
            ])
            ->assertCreated()
            ->json('sale');
    }

    private function customerOwing(User $user, float $quantity): Customer
    {
        $this->onCredit($user, $this->product($user), $quantity);

        return Customer::sole();
    }

    /** Take money against the khata and hand back the row to attach proof to. */
    private function settle(User $user, Customer $customer, float $amount): int
    {
        return (int) $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customer->id}/payments", [
                'amount' => $amount,
                'method' => 'jazzcash',
            ])
            ->assertOk()
            ->json('payment_id');
    }
}
