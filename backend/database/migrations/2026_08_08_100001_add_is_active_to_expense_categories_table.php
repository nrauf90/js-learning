<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give expense categories a retirement, because they cannot be given a death.
 *
 * The seeded list was a personal-finance one — Car Maintenance, Petrol, Car
 * Wash, Entertainment — and a kiryana store files none of it. Replacing the
 * list means the old rows have to go somewhere, and deleting them is not an
 * option: `cash_entries.category_id` is a restricted foreign key, so a delete
 * either fails outright or, if the constraint were ever relaxed, orphans real
 * money the shopkeeper entered by hand.
 *
 * `is_active` is the retirement. A deactivated category keeps naming the
 * entries already filed under it and simply stops being offered for new ones,
 * which is reversible — deletion is not.
 *
 * The retirement itself is data, not schema, so it lives in
 * ExpenseCategorySeeder where it can be re-run as the shop list evolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
