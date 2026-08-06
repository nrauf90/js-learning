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

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $category->user_id === $user->id;
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $category->user_id === $user->id;
    }
}
