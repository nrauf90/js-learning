<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->ownsKhata($user, $customer);
    }

    /**
     * Staff open pages and take money on them: udhaar is collected by whoever
     * is behind the counter when the customer walks in, not only by the owner.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->ownsKhata($user, $customer);
    }

    /** Taking money against a khata balance. */
    public function settle(User $user, Customer $customer): bool
    {
        return $this->ownsKhata($user, $customer);
    }

    /**
     * The khata belongs to the shop owner, so staff reach their own shop's
     * debtors and nobody reaches another shop's. Comparing against `id` would
     * hide every page from the cashier who opened it.
     */
    private function ownsKhata(User $user, Customer $customer): bool
    {
        return (int) $customer->user_id === $user->dataOwnerId();
    }
}
