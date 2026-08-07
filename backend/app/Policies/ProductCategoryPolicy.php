<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Same split as ProductPolicy: anyone may read the category list to work
     * the till, only the catalogue permission may reshape it.
     */
    public function create(User $user): bool
    {
        return $this->canManageCatalogue($user);
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $this->canManageCatalogue($user) && $this->ownsCatalogue($user, $category);
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $this->canManageCatalogue($user) && $this->ownsCatalogue($user, $category);
    }

    /** See ProductPolicy::canManageCatalogue() for why this is phrased this way. */
    private function canManageCatalogue(User $user): bool
    {
        return ! $user->isStaff() || $user->canManageCatalogue();
    }

    /** See ProductPolicy::ownsCatalogue(). */
    private function ownsCatalogue(User $user, ProductCategory $category): bool
    {
        $ownerId = (int) $category->user_id;

        return $ownerId === $user->dataOwnerId() || $ownerId === (int) $user->id;
    }
}
