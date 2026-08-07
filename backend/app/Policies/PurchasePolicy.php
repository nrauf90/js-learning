<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $purchase->user_id === $user->dataOwnerId();
    }

    /**
     * Booking a delivery in reprices the catalogue and moves stock, so it is
     * catalogue work rather than till work — the same permission ProductPolicy
     * guards its writes with. Suppliers ride on this too: they exist only to be
     * attached to a purchase.
     *
     * Written as "everyone except ungranted staff" rather than a straight
     * User::canManageCatalogue() call for the reason ProductPolicy spells out:
     * `role` carries a database-level default, so an owner instance created
     * in-process and never read back would otherwise be locked out.
     */
    public function create(User $user): bool
    {
        return ! $user->isStaff() || $user->canManageCatalogue();
    }
}
