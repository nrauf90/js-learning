<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    private function seller(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /**
     * A shop admin with a real shop row, plus a staff account working it. Both
     * are subscribed: the gate looks at each account's own subscription.
     *
     * @return array{0: User, 1: Shop}
     */
    private function shopWithOwner(string $name = 'Corner Store'): array
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => $name]);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        return [$owner->fresh(), $shop];
    }

    private function staffFor(Shop $shop, bool $canManageCatalogue = false): User
    {
        $staff = User::factory()->create();
        $this->subscribeUser($staff);
        $staff->assignRole(User::ROLE_STAFF, $shop->id);
        $staff->setProductPermission($canManageCatalogue);

        return $staff->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Basmati Rice 5kg',
            'price' => 2400,
            'cost' => 2100,
            'track_stock' => true,
            'stock_quantity' => 12,
            'low_stock_threshold' => 3,
            'is_active' => true,
        ], $attributes));
    }

    /* ------------------------------------------------------------ capturing */

    public function test_an_update_records_only_the_fields_that_changed(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", [
                'name' => $product->name,
                'price' => 2600,
                'cost' => 2100,
            ])
            ->assertOk();

        $log = ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertSame(ActivityLogger::UPDATED, $log->action);
        $this->assertSame('Product', $log->subject_type);
        $this->assertSame($product->id, $log->subject_id);
        $this->assertSame('Basmati Rice 5kg', $log->subject_label);
        $this->assertSame($user->name, $log->user_name);

        // Name and cost were resent unchanged; only the price moved.
        $this->assertSame(['price'], array_keys($log->changes));
        $this->assertSame('2400.0000', $log->changes['price']['from']);
        $this->assertSame('2600.0000', $log->changes['price']['to']);
    }

    public function test_a_no_op_update_records_nothing(): void
    {
        $user = $this->seller();
        $product = $this->product($user);
        ActivityLog::query()->delete();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", [
                'name' => 'Basmati Rice 5kg',
                'price' => 2400,
            ])
            ->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_creating_and_deleting_a_product_are_both_recorded(): void
    {
        $user = $this->seller();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', ['name' => 'Cooking Oil 1L', 'price' => 620])
            ->assertCreated()
            ->json('product.id');

        $created = ActivityLog::query()->latest('id')->firstOrFail();
        $this->assertSame(ActivityLogger::CREATED, $created->action);
        $this->assertSame('Cooking Oil 1L', $created->subject_label);
        $this->assertSame('620.0000', $created->changes['price']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/products/{$id}")->assertOk();

        $deleted = ActivityLog::query()->latest('id')->firstOrFail();
        $this->assertSame(ActivityLogger::DELETED, $deleted->action);
        // The row is gone, so the snapshot is the only record of what it held.
        $this->assertSame('Cooking Oil 1L', $deleted->subject_label);
        $this->assertSame('620.0000', $deleted->changes['price']);
    }

    public function test_a_stock_adjustment_records_the_person_and_the_reason(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 12]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => 20,
                'type' => 'restock',
                'note' => 'Tuesday delivery',
            ])
            ->assertOk();

        $log = ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertSame(ActivityLogger::STOCK_ADJUSTED, $log->action);
        $this->assertSame(12, $log->changes['stock_quantity']['from']);
        $this->assertSame(32, $log->changes['stock_quantity']['to']);
        $this->assertSame('restock', $log->changes['type']);
        $this->assertSame('Tuesday delivery', $log->changes['note']);
    }

    public function test_category_changes_are_recorded(): void
    {
        $user = $this->seller();

        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/product-categories', ['name' => 'Cold Drinks'])
            ->assertCreated()
            ->json('category.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/product-categories/{$id}", ['name' => 'Beverages'])
            ->assertOk();

        $log = ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertSame('ProductCategory', $log->subject_type);
        $this->assertSame(ActivityLogger::UPDATED, $log->action);
        $this->assertSame('Cold Drinks', $log->changes['name']['from']);
        $this->assertSame('Beverages', $log->changes['name']['to']);
        // The slug is derived from the name, so it moves in the same edit.
        $this->assertSame('beverages', $log->changes['slug']['to']);
    }

    public function test_stock_is_never_attributed_to_the_update_endpoint(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 12]);

        // PUT ignores stock_quantity by design; the trail must not claim
        // otherwise, or it would send someone hunting for a movement that
        // never happened.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2500, 'stock_quantity' => 999])
            ->assertOk();

        $log = ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('stock_quantity', $log->changes);
        $this->assertEquals(12, $product->fresh()->stock_quantity);
    }

    public function test_the_trail_outlives_the_person_who_made_the_change(): void
    {
        $user = $this->seller();
        $product = $this->product($user);
        $name = $user->name;

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2900])
            ->assertOk();

        $user->delete();

        $log = ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame($name, $log->user_name);
        $this->assertSame('Basmati Rice 5kg', $log->subject_label);
    }

    public function test_sensitive_fields_never_reach_the_trail(): void
    {
        $logger = app(ActivityLogger::class);
        $snapshot = $logger->snapshot($this->seller());

        $this->assertArrayNotHasKey('password', $snapshot);
        $this->assertArrayNotHasKey('remember_token', $snapshot);
    }

    /* -------------------------------------------------------------- report */

    public function test_a_guest_cannot_read_the_trail(): void
    {
        $this->getJson('/api/activity')->assertUnauthorized();
    }

    public function test_the_report_lists_the_shops_activity_newest_first(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $staff = $this->staffFor($shop, canManageCatalogue: true);
        $product = $this->product($owner);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2500])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/products', ['name' => 'Chilli Chips', 'price' => 80])
            ->assertCreated();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/activity')
            ->assertOk()
            ->assertJsonPath('activity.0.action', ActivityLogger::CREATED)
            ->assertJsonPath('activity.0.user_name', $staff->name)
            ->assertJsonPath('activity.1.action', ActivityLogger::UPDATED)
            ->assertJsonPath('activity.1.user_name', $owner->name);

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertCount(2, $response->json('actors'));
    }

    public function test_a_staff_member_cannot_read_another_shops_activity(): void
    {
        [$owner] = $this->shopWithOwner('Mine');
        [, $otherShop] = $this->shopWithOwner('Theirs');
        $outsider = $this->staffFor($otherShop, canManageCatalogue: true);

        $product = $this->product($owner);
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2700])
            ->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/activity')
            ->assertOk()
            ->assertJsonCount(0, 'activity');
    }

    public function test_a_till_only_staff_member_sees_only_their_own_actions(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $cashier = $this->staffFor($shop, canManageCatalogue: false);
        $product = $this->product($owner);

        // The owner's edit carries the shop's cost prices; a cashier has no
        // business reading them out of the audit trail either.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2800])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/activity')
            ->assertOk()
            ->assertJsonCount(0, 'activity');
    }

    public function test_a_solo_shop_admin_sees_only_their_own_trail(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->actingAs($theirs, 'sanctum')
            ->postJson('/api/products', ['name' => 'Not yours', 'price' => 10])
            ->assertCreated();

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/products', ['name' => 'Mine', 'price' => 20])
            ->assertCreated();

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/activity')
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.subject_label', 'Mine');
    }

    public function test_the_report_filters_by_action_subject_and_person(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $staff = $this->staffFor($shop, canManageCatalogue: true);
        $product = $this->product($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/product-categories', ['name' => 'Snacks'])
            ->assertCreated();

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 2650])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/activity?action='.ActivityLogger::CREATED)
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.subject_label', 'Snacks');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/activity?subject_type=Product')
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.action', ActivityLogger::UPDATED);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/activity?user_id='.$staff->id)
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.user_name', $staff->name);
    }

    public function test_the_report_filters_by_date_range(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 3000])
            ->assertOk();

        ActivityLog::query()->update(['created_at' => now()->subDays(10)]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/activity?from='.now()->subDays(3)->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'activity');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/activity?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'activity');
    }

    public function test_the_report_rejects_an_unknown_action_filter(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->getJson('/api/activity?action=dropped_the_database')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }

    public function test_a_failed_log_write_does_not_roll_back_the_change(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        Log::spy();

        // The trail is a witness to the write, not part of it. With the table
        // gone the insert throws, and the price change must still stand.
        Schema::drop('activity_logs');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/products/{$product->id}", ['price' => 3100])
            ->assertOk()
            ->assertJsonPath('product.price', 3100);

        $this->assertSame('3100.0000', $product->fresh()->price);

        // Swallowed, but not silently — an operator has to be able to find out.
        Log::shouldHaveReceived('error')->once();
    }
}
