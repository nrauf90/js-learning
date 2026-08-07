<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->ownsSale($user, $sale);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Refunding is the only mutation to the goods a completed sale allows. */
    public function refund(User $user, Sale $sale): bool
    {
        return $this->ownsSale($user, $sale);
    }

    /** Taking money against an outstanding balance. */
    public function settle(User $user, Sale $sale): bool
    {
        return $this->ownsSale($user, $sale);
    }

    /**
     * Sales belong to the shop owner, so staff reach their own shop's takings
     * and nobody reaches another shop's. Comparing against `id` instead would
     * hide every sale from the staff who rang it up.
     */
    private function ownsSale(User $user, Sale $sale): bool
    {
        return $sale->user_id === $user->dataOwnerId();
    }
}
