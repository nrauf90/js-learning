<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // GroceryCatalogSeeder is deliberately not called here — 23 categories
        // and 202 products with images is demo data, not baseline schema data.
        // Run it explicitly: `php artisan db:seed --class=GroceryCatalogSeeder`.
        $this->call([
            ExpenseCategorySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
