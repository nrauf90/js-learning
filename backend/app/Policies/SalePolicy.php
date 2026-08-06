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
        return $sale->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Refunding is the only mutation a completed sale allows. */
    public function refund(User $user, Sale $sale): bool
    {
        return $sale->user_id === $user->id;
    }
}
