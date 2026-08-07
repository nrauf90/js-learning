<?php

namespace App\Policies;

use App\Models\DayBalance;
use App\Models\User;

class DayBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Staff read and close their shop owner's day book, never their own — the
     * drawer they work belongs to the shop. Every query is already scoped to
     * `dataOwnerId()`, so this is the second lock on the same door: it holds
     * even if a future endpoint resolves a day book by id.
     */
    public function view(User $user, DayBalance $dayBalance): bool
    {
        return $dayBalance->user_id === $user->dataOwnerId();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function close(User $user, DayBalance $dayBalance): bool
    {
        return $dayBalance->user_id === $user->dataOwnerId();
    }
}
