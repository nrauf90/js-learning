<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * The caller's shop: the one they own if they are a shop admin, the one
     * they work at if they are staff. Readable by staff too — the till needs
     * the shop name and receipt footer to print a receipt.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'shop' => ($shop = $this->shopFor($request->user())) ? $this->payload($shop) : null,
        ]);
    }

    /**
     * Behind ShopAdminOnly, so the caller is always a shop admin editing the
     * shop they own — there is no shop id in the request to tamper with.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $shop = $user->ownedShop()->first();

        if (! $shop) {
            // Accounts that registered themselves (rather than being onboarded
            // by a platform admin) have no shop row yet, so the first save
            // creates it. owner_id comes from the token, never the request.
            $shop = new Shop(['owner_id' => $user->id]);
        }

        $shop->fill($validated)->save();

        // Keep the owner pointed at their own shop, so dataOwnerId() and the
        // staff list agree with users.shop_id for a self-registered account.
        if ((int) $user->shop_id !== (int) $shop->id) {
            $user->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);
        }

        return response()->json([
            'message' => 'Shop details saved.',
            'shop' => $this->payload($shop->fresh()),
        ]);
    }

    /**
     * Ownership first: a shop admin's own shop wins over users.shop_id, which
     * is only the working link staff accounts travel through.
     */
    private function shopFor(User $user): ?Shop
    {
        return $user->ownedShop()->first() ?? $user->shop()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'phone' => $shop->phone,
            'address' => $shop->address,
            'receipt_footer' => $shop->receipt_footer,
            'logo_url' => $shop->logoUrl(),
        ];
    }
}
