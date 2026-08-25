<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AttachmentController;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Shop;
use App\Models\User;
use App\Services\ReceiptImageStore;
use App\Support\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * The paperwork behind stock that came in and money that went out.
 *
 * A wholesaler's boy leaves a hand-written bill; three weeks later the
 * wholesaler says an instalment never arrived. Until this existed the shop's
 * answer was whether anyone still had the paper. Now the bill is photographed
 * when the delivery is booked in, and the JazzCash screenshot is filed against
 * the instalment it paid.
 *
 * These are money documents, not shelf photos, so the tests below care as much
 * about who *cannot* reach them as about the happy path.
 */
class PurchaseAttachmentTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real local disk under storage/framework/testing: uploads genuinely
        // land on it, they just do not pollute the dev shop's receipts.
        Storage::fake('receipts');
    }

    /* ---------------------------------------------------------- happy path */

    public function test_a_bill_photographed_at_the_door_is_filed_against_the_delivery(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg', 900, 1400),
                'caption' => 'Wholesaler bill, 200 kg atta',
            ])
            ->assertCreated();

        $attachment = Attachment::sole();

        $this->assertTrue(Storage::disk('receipts')->exists($attachment->path));
        $this->assertStringStartsWith(ReceiptImageStore::PURCHASE_DIR.'/', $attachment->path);
        $this->assertSame($user->id, (int) $attachment->user_id);
        $this->assertSame('Wholesaler bill, 200 kg atta', $attachment->caption);

        $response->assertJsonPath('attachment.id', $attachment->id);
        $response->assertJsonPath('attachment.url', '/api/attachments/'.$attachment->id);
    }

    public function test_a_transfer_screenshot_is_filed_against_the_instalment_it_paid(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $paid = $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase->id}/payments", [
                'amount' => 15000,
                'method' => 'bank_transfer',
                'reference' => 'TRX-99881',
            ])
            ->assertOk();

        $paymentId = $paid->json('purchase.payments.0.id');
        $this->assertNotNull($paymentId, 'the payment response must name the instalment it wrote');

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchase-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('jazzcash.png', 720, 1500),
            ])
            ->assertCreated();

        $attachment = Attachment::sole();
        $this->assertSame(PurchasePayment::class, $attachment->attachable_type);
        $this->assertSame((int) $paymentId, (int) $attachment->attachable_id);
        $this->assertStringStartsWith(ReceiptImageStore::PAYMENT_DIR.'/', $attachment->path);
    }

    public function test_the_invoice_screen_reads_back_both_the_bill_and_each_screenshot(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])->assertCreated();

        $paymentId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/purchases/{$purchase->id}/payments", ['amount' => 5000, 'method' => 'cash'])
            ->json('purchase.payments.0.id');

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchase-payments/{$paymentId}/attachments", [
                'image' => UploadedFile::fake()->image('slip.jpg'),
                'caption' => 'Signed slip',
            ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonCount(1, 'purchase.attachments')
            ->assertJsonCount(1, 'purchase.payments.0.attachments')
            ->assertJsonPath('purchase.payments.0.attachments.0.caption', 'Signed slip');
    }

    /**
     * A long bill is photographed in pieces. One column on `purchases` could
     * never have held that, which is why the table is polymorphic.
     */
    public function test_a_delivery_can_carry_several_photographs(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        foreach (['page-1.jpg', 'page-2.jpg', 'page-3.jpg'] as $name) {
            $this->actingAs($user, 'sanctum')
                ->post("/api/purchases/{$purchase->id}/attachments", [
                    'image' => UploadedFile::fake()->image($name),
                ])->assertCreated();
        }

        $this->assertSame(3, $purchase->attachments()->count());
    }

    /* ----------------------------------------------------------- streaming */

    public function test_the_file_is_served_back_to_the_shop_that_uploaded_it(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg', 400, 600),
            ])->json('attachment.id');

        $response = $this->actingAs($user, 'sanctum')->get("/api/attachments/{$id}");

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // A shared cache must never hold a document that took an authorisation
        // check to reach.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_guest_cannot_read_a_receipt(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])->json('attachment.id');

        // actingAs() above stays in force for the rest of the test, so the
        // guard has to be dropped or this would assert nothing at all.
        $this->app->make('auth')->forgetGuards();

        $this->getJson("/api/attachments/{$id}")->assertUnauthorized();
    }

    /* ------------------------------------------------------ shop boundary */

    public function test_another_shop_cannot_read_your_receipt(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])->json('attachment.id');

        $stranger = $this->shopkeeper();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/attachments/{$id}")
            ->assertForbidden();
    }

    public function test_another_shop_cannot_delete_your_receipt(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])->json('attachment.id');

        $stranger = $this->shopkeeper();

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/attachments/{$id}")
            ->assertForbidden();

        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_another_shop_cannot_attach_anything_to_your_delivery(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $stranger = $this->shopkeeper();

        $this->actingAs($stranger, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('forged.jpg'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attachments', 0);
    }

    /* ------------------------------------------------------- staff and roles */

    /**
     * Booking a delivery in is catalogue work, and so is filing its bill. The
     * clerk who may not receive stock may not file paperwork against it either.
     */
    public function test_a_till_only_staff_account_cannot_attach_a_receipt(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Galla Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $purchase = $this->delivery($owner);

        $clerk = User::factory()->create();
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(false);

        $this->actingAs($clerk, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_staff_with_the_catalogue_permission_can_attach_a_receipt(): void
    {
        $owner = $this->shopkeeper();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Galla Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $purchase = $this->delivery($owner);

        $clerk = User::factory()->create();
        $clerk->assignRole(User::ROLE_STAFF, $shop->id);
        $clerk->setProductPermission(true);

        $this->actingAs($clerk, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])
            ->assertCreated();

        // Filed under the shop, not under the clerk — otherwise the owner would
        // not see their own shop's paperwork.
        $this->assertSame($owner->id, (int) Attachment::sole()->user_id);
        $this->assertSame($clerk->id, (int) Attachment::sole()->uploaded_by);
    }

    /* ------------------------------------------------ what will not be stored */

    public function test_a_php_payload_wearing_a_jpeg_header_is_rejected(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->createWithContent(
                    'bill.jpg',
                    "\xFF\xD8\xFF\xE0".'<?php echo shell_exec($_GET["c"]); ?>'
                ),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_an_svg_is_rejected(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->createWithContent(
                    'bill.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
                ),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->create('huge.jpg', ReceiptImageStore::MAX_KILOBYTES + 1, 'image/jpeg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    /**
     * The stored name is built from the row id and the decoded image header,
     * so an upload called "../../shell.php" cannot escape the directory and
     * cannot land with an executable extension.
     */
    public function test_the_stored_filename_is_not_taken_from_the_upload(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('../../../evil.php.jpg'),
            ])
            ->assertCreated();

        $path = Attachment::sole()->path;

        $this->assertStringStartsWith(ReceiptImageStore::PURCHASE_DIR.'/purchase-'.$purchase->id.'-', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringEndsWith('.jpg', $path);
    }

    public function test_a_record_cannot_be_used_as_a_photo_album(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        for ($i = 0; $i < AttachmentController::MAX_PER_RECORD; $i++) {
            $this->actingAs($user, 'sanctum')
                ->post("/api/purchases/{$purchase->id}/attachments", [
                    'image' => UploadedFile::fake()->image("page-{$i}.jpg"),
                ])->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('one-too-many.jpg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    /* -------------------------------------------------------------- deletes */

    public function test_removing_an_attachment_takes_the_file_with_it(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])->json('attachment.id');

        $path = Attachment::find($id)->path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/attachments/{$id}")
            ->assertOk();

        $this->assertDatabaseCount('attachments', 0);
        $this->assertFalse(Storage::disk('receipts')->exists($path));
    }

    /* -------------------------------------------------------- subscription */

    public function test_a_lapsed_shop_cannot_attach_a_receipt(): void
    {
        $user = $this->shopkeeper();
        $purchase = $this->delivery($user);

        $user->subscriptions()->delete();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->post("/api/purchases/{$purchase->id}/attachments", [
                'image' => UploadedFile::fake()->image('bill.jpg'),
            ])
            ->assertStatus(402);
    }

    /* ------------------------------------------------------------ fixtures */

    private function shopkeeper(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /** A Rs 40,000 delivery — 200 kg of atta at Rs 200 the kilo. */
    private function delivery(User $user): Purchase
    {
        $product = Product::create([
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

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/purchases', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 200, 'unit_cost' => 200],
                ],
            ])
            ->assertCreated()
            ->json('purchase.id');

        return Purchase::findOrFail($id);
    }
}
