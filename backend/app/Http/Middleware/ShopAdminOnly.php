<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for "this shop's owner only" endpoints — shop details and staff.
 *
 * Deliberately does NOT let platform admins through. They onboard shops
 * (AdminController::shopAdminStore) but have no shop of their own, so every
 * handler behind this would have to invent one for them; letting them in would
 * mean silently operating on somebody else's staff list.
 *
 * Referenced by class name in routes/api.php rather than through an alias so
 * the whole feature stays inside its own files.
 */
class ShopAdminOnly
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Platform admins are turned away first: they carry the default
        // shop_admin role (is_admin is the authority for them, not role), and
        // letting them through would mean every handler behind this had to
        // decide which shop "theirs" means.
        //
        // Otherwise checked against the role column — a staff account that
        // reached here is exactly what this is for. Null falls back to
        // shop_admin the same way User::toAuthArray() does, so an account that
        // predates the roles column is not locked out of its own shop.
        if ($user->isPlatformAdmin() || ($user->role ?? User::ROLE_SHOP_ADMIN) !== User::ROLE_SHOP_ADMIN) {
            return response()->json([
                'message' => 'Only a shop owner can manage the shop and its staff.',
            ], 403);
        }

        return $next($request);
    }
}
