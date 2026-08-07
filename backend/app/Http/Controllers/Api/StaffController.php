<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff accounts, managed by the shop admin who owns them.
 *
 * Every route here is behind ShopAdminOnly, so `$request->user()` is always a
 * shop admin. What that middleware cannot check is *which* staff row is being
 * touched, so each method re-resolves the caller's own shop and refuses to see
 * anything outside it — an id from another shop 404s rather than 403s, so the
 * response cannot be used to probe for accounts that exist elsewhere.
 */
class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request->user());

        $staff = User::query()
            ->where('shop_id', $shop->id)
            ->where('role', User::ROLE_STAFF)
            ->orderBy('name')
            ->get()
            ->map(fn (User $member) => $this->payload($member));

        return response()->json(['staff' => $staff]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request->user());

        // Same password rules as AuthController::register — a staff login is a
        // login like any other, and this one is chosen by somebody else.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'can_manage_products' => ['sometimes', 'boolean'],
        ]);

        $member = DB::transaction(function () use ($validated, $request, $shop) {
            // Only name/email/password are mass-assigned; role, shop_id and
            // can_manage_products are set through the explicit setters below
            // precisely so a stray `"role": "admin"` in the body does nothing.
            $member = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $member->assignRole(User::ROLE_STAFF, $shop->id);
            $member->setProductPermission($request->boolean('can_manage_products'));

            return $member;
        });

        return response()->json(['staff' => $this->payload($member->fresh())], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $member = $this->requireOwnStaff($request->user(), $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'password' => ['sometimes', 'confirmed', Password::defaults()],
            'can_manage_products' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $request, $member) {
            $member->fill(Arr::only($validated, ['name', 'email', 'password']));
            $member->save();

            if ($request->has('can_manage_products')) {
                $member->setProductPermission($request->boolean('can_manage_products'));
            }

            // A new password ends every session the staff member had open,
            // which is the point of a shop admin resetting it.
            if (isset($validated['password'])) {
                $member->tokens()->delete();
            }
        });

        return response()->json(['staff' => $this->payload($member->fresh())]);
    }

    /**
     * Deactivate, not delete. The row survives untouched, so every sale, stock
     * movement and activity_logs entry that points at this user keeps its
     * cashier name and its history.
     *
     * Deactivation is modelled as "unlinked from the shop": the account keeps
     * the staff role but no longer resolves to the shop's data through
     * User::dataOwnerId(), and its tokens are revoked so any open till session
     * stops working immediately rather than at the next login.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $member = $this->requireOwnStaff($request->user(), $user);

        DB::transaction(function () use ($member) {
            // assignRole() reads a null shop id as "leave it alone", so the
            // unlink is written directly. Still a forceFill: shop_id is out of
            // mass assignment on purpose.
            $member->forceFill(['shop_id' => null, 'can_manage_products' => false])->save();
            $member->tokens()->delete();
        });

        return response()->json(['message' => 'Staff member deactivated.']);
    }

    /**
     * A shop admin with no shop row yet (an account that self-registered
     * before shops existed) has nothing to hang staff off. 409 rather than 404
     * — the request is fine, the account just is not set up yet.
     */
    private function requireShop(User $user): Shop
    {
        $shop = $user->ownedShop()->first();

        if (! $shop) {
            abort(409, 'Add your shop details before creating staff.');
        }

        return $shop;
    }

    /** The one check that stops a shop admin reaching into another shop. */
    private function requireOwnStaff(User $actor, User $target): User
    {
        $shop = $this->requireShop($actor);

        if (! $target->isStaff() || (int) $target->shop_id !== (int) $shop->id) {
            throw new NotFoundHttpException('Staff member not found.');
        }

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->role,
            'shop_id' => $member->shop_id,
            'can_manage_products' => (bool) $member->can_manage_products,
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }
}
