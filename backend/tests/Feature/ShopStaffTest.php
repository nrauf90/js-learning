<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Shops, staff accounts and the catalogue permission.
 *
 * The bulk of this file is escalation attempts rather than happy paths: the
 * whole point of the roles is that a cashier cannot promote themselves and a
 * shop owner cannot reach into the shop next door.
 */
class ShopStaffTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @return array{0: User, 1: Shop}
     */
    private function shopWithOwner(string $shopName = 'Corner Store'): array
    {
        $owner = User::factory()->create();
        $this->subscribeUser($owner);

        $shop = Shop::create(['owner_id' => $owner->id, 'name' => $shopName]);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        return [$owner->fresh(), $shop];
    }

    private function staffFor(Shop $shop, bool $catalogue = false): User
    {
        $member = User::factory()->create();
        $this->subscribeUser($member);
        $member->assignRole(User::ROLE_STAFF, $shop->id);
        $member->setProductPermission($catalogue);

        return $member->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(User $owner, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'track_stock' => true,
            'stock_quantity' => 10,
            'is_active' => true,
        ], $attributes));
    }

    /* ------------------------------------------------ platform onboarding */

    public function test_platform_admin_creates_a_shop_admin_with_their_shop(): void
    {
        $response = $this->actingAs($this->platformAdmin(), 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Bilal Khan',
                'email' => 'bilal@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'shop_name' => 'Khan Kiryana',
                'shop_phone' => '0300-1234567',
                'shop_address' => '12 Mall Road, Lahore',
            ])
            ->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_SHOP_ADMIN)
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonPath('shop.name', 'Khan Kiryana');

        $owner = User::where('email', 'bilal@shop.test')->firstOrFail();
        $shop = Shop::findOrFail($response->json('shop.id'));

        $this->assertSame(User::ROLE_SHOP_ADMIN, $owner->role);
        $this->assertFalse($owner->is_admin);
        $this->assertSame($owner->id, $shop->owner_id);
        // The link goes both ways: users.shop_id is what staff and the till
        // resolve the shop through.
        $this->assertSame($shop->id, $owner->shop_id);
    }

    public function test_onboarding_ignores_privilege_fields_in_the_request(): void
    {
        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Sneaky',
                'email' => 'sneaky@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'shop_name' => 'Sneaky Store',
                'is_admin' => true,
                'role' => User::ROLE_ADMIN,
                'can_manage_products' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('user.is_admin', false);

        $owner = User::where('email', 'sneaky@shop.test')->firstOrFail();

        $this->assertFalse($owner->is_admin);
        $this->assertSame(User::ROLE_SHOP_ADMIN, $owner->role);
    }

    public function test_onboarding_uses_the_registration_password_rules(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Weak',
                'email' => 'weak@shop.test',
                'password' => 'short',
                'password_confirmation' => 'short',
                'shop_name' => 'Weak Store',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Mismatch',
                'email' => 'mismatch@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Other-Passw0rd',
                'shop_name' => 'Mismatch Store',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_a_shop_admin_cannot_onboard_other_shops(): void
    {
        [$owner] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Rival',
                'email' => 'rival@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'shop_name' => 'Rival Store',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rival@shop.test']);
    }

    public function test_a_staff_member_cannot_onboard_shops(): void
    {
        [, $shop] = $this->shopWithOwner();

        $this->actingAs($this->staffFor($shop), 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Rival',
                'email' => 'rival@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'shop_name' => 'Rival Store',
            ])
            ->assertForbidden();
    }

    public function test_platform_admin_lists_shop_admins_with_their_shops(): void
    {
        [, $shop] = $this->shopWithOwner('Khan Kiryana');
        $this->staffFor($shop);

        $response = $this->actingAs($this->platformAdmin(), 'sanctum')
            ->getJson('/api/admin/shop-admins')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Khan Kiryana', $response->json('data.0.shop.name'));
        $this->assertSame(1, $response->json('data.0.staff_count'));
    }

    public function test_a_platform_admin_is_not_treated_as_a_shop_admin(): void
    {
        // They carry the default shop_admin role, so the guard has to look at
        // is_admin as well or they would land in some arbitrary shop.
        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->getJson('/api/staff')
            ->assertForbidden();
    }

    /* ------------------------------------------------------------- the shop */

    public function test_shop_admin_reads_and_updates_their_own_shop(): void
    {
        [$owner, $shop] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('shop.id', $shop->id);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/shop', [
                'name' => 'Khan Superstore',
                'phone' => '0300-7654321',
                'address' => 'Gulberg, Lahore',
                'receipt_footer' => 'Thank you!',
            ])
            ->assertOk()
            ->assertJsonPath('shop.name', 'Khan Superstore');

        $this->assertDatabaseHas('shops', [
            'id' => $shop->id,
            'name' => 'Khan Superstore',
            'receipt_footer' => 'Thank you!',
        ]);
    }

    public function test_a_shop_admin_only_ever_sees_their_own_shop(): void
    {
        [$owner] = $this->shopWithOwner('Mine');
        [, $theirs] = $this->shopWithOwner('Theirs');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('shop.name', 'Mine');

        // There is no shop id in the update request at all, so the only shop
        // this can reach is the caller's own.
        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/shop', ['name' => 'Hijacked'])
            ->assertOk();

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_staff_read_their_shop_but_cannot_change_it(): void
    {
        [, $shop] = $this->shopWithOwner('Khan Kiryana');
        $member = $this->staffFor($shop);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('shop.name', 'Khan Kiryana');

        $this->actingAs($member, 'sanctum')
            ->putJson('/api/shop', ['name' => 'Staff Store'])
            ->assertForbidden();

        $this->assertSame('Khan Kiryana', $shop->fresh()->name);
    }

    public function test_a_self_registered_shop_admin_gets_a_shop_on_first_save(): void
    {
        // Accounts that predate shops: role defaults to shop_admin, no shop row.
        $owner = User::factory()->create();
        $this->subscribeUser($owner);

        $this->actingAs($owner->fresh(), 'sanctum')
            ->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('shop', null);

        $this->actingAs($owner->fresh(), 'sanctum')
            ->putJson('/api/shop', ['name' => 'Late Setup'])
            ->assertOk()
            ->assertJsonPath('shop.name', 'Late Setup');

        $shop = Shop::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame($shop->id, $owner->fresh()->shop_id);
    }

    /* ------------------------------------------------------------ the staff */

    public function test_shop_admin_creates_staff_for_their_own_shop(): void
    {
        [$owner, $shop] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Ayesha',
                'email' => 'ayesha@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
            ])
            ->assertCreated()
            ->assertJsonPath('staff.role', User::ROLE_STAFF)
            ->assertJsonPath('staff.shop_id', $shop->id)
            // Till-only until the owner says otherwise.
            ->assertJsonPath('staff.can_manage_products', false);

        $member = User::where('email', 'ayesha@shop.test')->firstOrFail();
        $this->assertSame(User::ROLE_STAFF, $member->role);
        $this->assertSame($shop->id, $member->shop_id);
        $this->assertFalse($member->is_admin);
        // Staff work the owner's catalogue and takings, not their own.
        $this->assertSame($owner->id, $member->dataOwnerId());
    }

    public function test_a_shop_admin_cannot_create_a_platform_admin(): void
    {
        [$owner] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Trojan',
                'email' => 'trojan@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'is_admin' => true,
                'role' => User::ROLE_ADMIN,
            ])
            ->assertCreated();

        $member = User::where('email', 'trojan@shop.test')->firstOrFail();
        $this->assertFalse($member->is_admin);
        $this->assertSame(User::ROLE_STAFF, $member->role);
    }

    public function test_a_shop_admin_cannot_plant_staff_in_another_shop(): void
    {
        [$owner] = $this->shopWithOwner('Mine');
        [, $theirs] = $this->shopWithOwner('Theirs');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Mole',
                'email' => 'mole@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
                'shop_id' => $theirs->id,
            ])
            ->assertCreated();

        $this->assertNotSame($theirs->id, User::where('email', 'mole@shop.test')->firstOrFail()->shop_id);
    }

    public function test_staff_passwords_follow_the_registration_rules(): void
    {
        [$owner] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Weak',
                'email' => 'weak@shop.test',
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_shop_admin_lists_only_their_own_staff(): void
    {
        [$owner, $shop] = $this->shopWithOwner('Mine');
        [, $theirs] = $this->shopWithOwner('Theirs');

        $mine = $this->staffFor($shop);
        $this->staffFor($theirs);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/staff')
            ->assertOk();

        $this->assertCount(1, $response->json('staff'));
        $this->assertSame($mine->id, $response->json('staff.0.id'));
    }

    public function test_shop_admin_cannot_touch_another_shops_staff(): void
    {
        [$owner] = $this->shopWithOwner('Mine');
        [, $theirs] = $this->shopWithOwner('Theirs');
        $notMine = $this->staffFor($theirs);

        // 404 rather than 403: the response must not confirm the account even
        // exists, or it becomes a way to enumerate other shops' staff.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/staff/{$notMine->id}", ['can_manage_products' => true])
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/staff/{$notMine->id}")
            ->assertNotFound();

        $notMine->refresh();
        $this->assertFalse($notMine->can_manage_products);
        $this->assertSame($theirs->id, $notMine->shop_id);
    }

    public function test_a_shop_admin_cannot_move_staff_to_another_shop(): void
    {
        [$owner, $shop] = $this->shopWithOwner('Mine');
        [, $theirs] = $this->shopWithOwner('Theirs');
        $member = $this->staffFor($shop);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/staff/{$member->id}", [
                'name' => 'Renamed',
                'shop_id' => $theirs->id,
                'role' => User::ROLE_SHOP_ADMIN,
                'is_admin' => true,
            ])
            ->assertOk();

        $member->refresh();
        $this->assertSame('Renamed', $member->name);
        $this->assertSame($shop->id, $member->shop_id);
        $this->assertSame(User::ROLE_STAFF, $member->role);
        $this->assertFalse($member->is_admin);
    }

    public function test_a_shop_admin_cannot_use_the_staff_endpoint_on_themselves(): void
    {
        [$owner] = $this->shopWithOwner();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/staff/{$owner->id}", ['can_manage_products' => true])
            ->assertNotFound();
    }

    public function test_staff_cannot_manage_staff(): void
    {
        [, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);
        $colleague = $this->staffFor($shop);

        $this->actingAs($member, 'sanctum')->getJson('/api/staff')->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Friend',
                'email' => 'friend@shop.test',
                'password' => 'Str0ng-Passw0rd',
                'password_confirmation' => 'Str0ng-Passw0rd',
            ])
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/staff/{$colleague->id}")
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'friend@shop.test']);
        $this->assertSame($shop->id, $colleague->fresh()->shop_id);
    }

    public function test_staff_cannot_grant_themselves_the_catalogue(): void
    {
        [, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/staff/{$member->id}", ['can_manage_products' => true])
            ->assertForbidden();

        $this->assertFalse($member->fresh()->can_manage_products);
    }

    public function test_deactivating_staff_keeps_their_sales_history(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);

        $sale = Sale::create([
            'user_id' => $member->id,
            'subtotal' => 500,
            'total' => 500,
            'sold_at' => now(),
        ]);
        $member->createToken('till');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/staff/{$member->id}")
            ->assertOk();

        $member->refresh();
        $this->assertNull($member->shop_id);
        $this->assertFalse($member->can_manage_products);
        // The account is unlinked, never deleted — the sale still names them.
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'user_id' => $member->id]);
        // And any till they left open stops working now, not at next login.
        $this->assertSame(0, $member->tokens()->count());

        $this->assertCount(0, $this->actingAs($owner, 'sanctum')->getJson('/api/staff')->json('staff'));
    }

    /* --------------------------------------------- the catalogue permission */

    public function test_staff_without_the_permission_cannot_write_products(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);
        $product = $this->product($owner);

        $acting = fn () => $this->actingAs($member, 'sanctum');

        $acting()->postJson('/api/products', ['name' => 'Chips', 'price' => 60])->assertForbidden();
        $acting()->putJson("/api/products/{$product->id}", ['price' => 1])->assertForbidden();
        $acting()->deleteJson("/api/products/{$product->id}")->assertForbidden();
        $acting()->postJson("/api/products/{$product->id}/stock", [
            'quantity_delta' => 50,
            'type' => 'restock',
        ])->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Chips']);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_staff_without_the_permission_cannot_write_categories(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);
        $category = ProductCategory::create([
            'user_id' => $owner->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/product-categories', ['name' => 'Snacks'])
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/product-categories/{$category->id}", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/product-categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseMissing('product_categories', ['name' => 'Snacks']);
        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'name' => 'Drinks']);
    }

    public function test_staff_can_still_read_the_catalogue_to_work_the_till(): void
    {
        [, $shop] = $this->shopWithOwner();

        $this->actingAs($this->staffFor($shop), 'sanctum')
            ->getJson('/api/products')
            ->assertOk();

        $this->actingAs($this->staffFor($shop), 'sanctum')
            ->getJson('/api/product-categories')
            ->assertOk();
    }

    public function test_granting_the_permission_opens_the_catalogue(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/staff/{$member->id}", ['can_manage_products' => true])
            ->assertOk()
            ->assertJsonPath('staff.can_manage_products', true);

        $this->actingAs($member->fresh(), 'sanctum')
            ->postJson('/api/products', ['name' => 'Chips', 'price' => 60])
            ->assertCreated();

        $this->actingAs($member->fresh(), 'sanctum')
            ->postJson('/api/product-categories', ['name' => 'Snacks'])
            ->assertCreated();
    }

    public function test_revoking_the_permission_closes_it_again(): void
    {
        [$owner, $shop] = $this->shopWithOwner();
        $member = $this->staffFor($shop, catalogue: true);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/staff/{$member->id}", ['can_manage_products' => false])
            ->assertOk()
            ->assertJsonPath('staff.can_manage_products', false);

        $this->actingAs($member->fresh(), 'sanctum')
            ->postJson('/api/products', ['name' => 'Chips', 'price' => 60])
            ->assertForbidden();
    }

    public function test_a_shop_admin_keeps_the_catalogue_without_the_flag(): void
    {
        // can_manage_products is a staff-only grant; an owner never needs it.
        [$owner] = $this->shopWithOwner();

        $this->assertFalse($owner->can_manage_products);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/products', ['name' => 'Chips', 'price' => 60])
            ->assertCreated();
    }
}
