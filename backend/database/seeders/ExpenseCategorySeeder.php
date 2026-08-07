<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * What a shop actually spends money on.
 *
 * Slugs are written out rather than derived from the name, because the slug is
 * the identity a `cash_entries` row is already attached to and the name is only
 * a label. That is what lets "Utilities" become "Bijli/Gas (Utilities)" and
 * "Sales" become "Shop Sales" without breaking a single existing entry — the
 * concept did not change, only the words a shopkeeper recognises it by.
 */
class ExpenseCategorySeeder extends Seeder
{
    /** @var array<string, string> slug => name */
    private const EXPENSE_CATEGORIES = [
        'stock-purchase' => 'Stock Purchase',
        'rent' => 'Rent',
        'utilities' => 'Bijli/Gas (Utilities)',
        'staff-salary' => 'Staff Salary',
        'transport' => 'Transport/Delivery',
        'packaging' => 'Packaging',
        'wastage' => 'Wastage',
        'repairs' => 'Repairs',
        'chanda-zakat' => 'Chanda/Zakat',
        // Cash the owner takes out for himself. Not a cost of trading, but it
        // leaves the drawer, so it has to be filed somewhere or the day will
        // never reconcile.
        'owner-drawings' => 'Owner Drawings',
        'other' => 'Other',
    ];

    /**
     * Manually entered takings only. POS sales no longer post here — the till
     * books an opening float and a closing count per day under its own
     * categories. See DayBookService.
     *
     * @var array<string, string> slug => name
     */
    private const INCOME_CATEGORIES = [
        'sales' => 'Shop Sales',
        'other-income' => 'Other Income',
    ];

    public function run(): void
    {
        $this->seedKind(self::EXPENSE_CATEGORIES, 'expense');
        $this->seedKind(self::INCOME_CATEGORIES, 'income');
        $this->retireEverythingElse();
    }

    /**
     * @param  array<string, string>  $categories
     */
    private function seedKind(array $categories, string $kind): void
    {
        foreach ($categories as $slug => $name) {
            ExpenseCategory::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'kind' => $kind,
                    'icon' => null,
                    'is_system' => true,
                ]
            );
        }

        // `is_active` is deliberately not mass assignable, and a re-run has to
        // be able to bring a category back out of retirement.
        ExpenseCategory::query()
            ->whereIn('slug', array_keys($categories))
            ->update(['is_active' => true]);
    }

    /**
     * Retire the personal-finance categories this list replaces — Car
     * Maintenance, Petrol, Entertainment and the rest.
     *
     * They are deactivated, never deleted. `cash_entries.category_id` is a
     * restricted foreign key, so deleting a category a shopkeeper has already
     * filed real money under would fail outright; and even where it succeeded
     * it would erase the only record of what that money was spent on. A
     * deactivated row keeps naming its old entries and simply stops being
     * offered for new ones.
     *
     * Scoped to seeded rows so a category the shop added itself is never swept
     * up, and the day book's own two are left alone — they are created on first
     * till open and are not part of any seeded list.
     */
    private function retireEverythingElse(): void
    {
        ExpenseCategory::query()
            ->where('is_system', true)
            ->whereNotIn('slug', [
                ...array_keys(self::EXPENSE_CATEGORIES),
                ...array_keys(self::INCOME_CATEGORIES),
                ...ExpenseCategory::INTERNAL_SLUGS,
            ])
            ->update(['is_active' => false]);
    }
}
