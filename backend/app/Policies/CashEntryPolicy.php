<?php

namespace App\Policies;

use App\Models\CashEntry;
use App\Models\User;

class CashEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CashEntry $cashEntry): bool
    {
        return $cashEntry->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CashEntry $cashEntry): bool
    {
        return $cashEntry->user_id === $user->id;
    }

    public function delete(User $user, CashEntry $cashEntry): bool
    {
        return $cashEntry->user_id === $user->id;
    }
}
