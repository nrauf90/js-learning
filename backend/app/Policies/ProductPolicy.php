<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return $this->ownsCatalogue($user, $product);
    }

    /**
     * Reads stay open to everyone — a cashier without the catalogue permission
     * still has to find things to sell. Writes below are where the permission
     * bites, and it is enforced here rather than in the controller so the till,
     * the products screen and the stock adjustment all inherit it.
     */
    public function create(User $user): bool
    {
        return $this->canManageCatalogue($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManageCatalogue($user) && $this->ownsCatalogue($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canManageCatalogue($user) && $this->ownsCatalogue($user, $product);
    }

    /**
     * Staff are till-only until their shop admin grants them the catalogue.
     *
     * Written as "everyone except ungranted staff" rather than a straight
     * User::canManageCatalogue() call because `role` carries a database-level
     * default: a User instance created in-process and never read back has a
     * null role, which would otherwise read as "not a shop admin" and lock an
     * owner out of their own products.
     */
    private function canManageCatalogue(User $user): bool
    {
        return ! $user->isStaff() || $user->canManageCatalogue();
    }

    /**
     * A shop's catalogue hangs off the owner's user id, so staff are matched
     * against their shop owner rather than themselves — otherwise a cashier
     * with the permission could see the catalogue but not edit any of it.
     * Their own id still counts, for rows they created before joining a shop.
     */
    private function ownsCatalogue(User $user, Product $product): bool
    {
        $ownerId = (int) $product->user_id;

        return $ownerId === $user->dataOwnerId() || $ownerId === (int) $user->id;
    }
}
