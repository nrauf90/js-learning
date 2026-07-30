<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $expenseCategories = [
            'Car Maintenance',
            'Petrol',
            'Car Wash',
            'Grocery',
            'Utilities',
            'Food',
            'Transport',
            'Health',
            'Entertainment',
            'Other',
        ];

        foreach ($expenseCategories as $name) {
            ExpenseCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'kind' => 'expense',
                    'icon' => null,
                    'is_system' => true,
                ]
            );
        }

        $incomeCategories = [
            'Salary',
            'Freelance',
            'Business',
            'Investment',
            'Other Income',
        ];

        foreach ($incomeCategories as $name) {
            ExpenseCategory::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'kind' => 'income',
                    'icon' => null,
                    'is_system' => true,
                ]
            );
        }
    }
}
