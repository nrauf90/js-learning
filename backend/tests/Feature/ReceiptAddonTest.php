<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\SubscriptionAddon;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class ReceiptAddonTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    public function test_cash_entries_receipt_path_is_nullable(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('kind', 'expense')->firstOrFail();

        $entry = CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => '2026-07-30',
            'receipt_path' => null,
        ]);

        $this->assertNull($entry->fresh()->receipt_path);
    }

    public function test_receipt_upload_stub_returns_501_for_subscribed_user(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/receipts/upload')
            ->assertStatus(501)
            ->assertJson(['message' => 'Coming soon']);
    }

    public function test_receipt_upload_requires_subscription(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/receipts/upload')
            ->assertStatus(402);
    }

    public function test_subscription_endpoint_includes_addon_status(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $this->activateReceiptAddon($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('addons.receipt.active', true)
            ->assertJsonStructure(['addons' => ['receipt' => ['active', 'ends_at']]]);
    }

    public function test_sandbox_receipt_addon_payment_creates_addon_record(): void
    {
        config(['billing.sandbox' => true]);

        $user = User::factory()->create();
        $this->subscribeUser($user);

        $checkout = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan' => 'receipt_addon',
                'provider' => 'jazzcash',
            ])
            ->assertCreated();

        $paymentId = $checkout->json('payment.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('addons.receipt.active', true);

        $this->assertDatabaseHas('subscription_addons', [
            'user_id' => $user->id,
            'addon_key' => 'receipt',
            'status' => 'active',
        ]);
    }

    public function test_addon_can_be_flagged_active_manually(): void
    {
        $user = User::factory()->create();

        SubscriptionAddon::create([
            'user_id' => $user->id,
            'addon_key' => 'receipt',
            'status' => 'active',
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertJsonPath('addons.receipt.active', true);
    }
}
