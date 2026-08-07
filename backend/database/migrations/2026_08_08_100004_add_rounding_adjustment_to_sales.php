<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charge whole rupees, and record what rounding did.
 *
 * Paisa coins have not circulated in Pakistan for years — the smallest thing in
 * the drawer is a one-rupee coin. A ticket that comes to Rs 62.50 is settled at
 * Rs 62 or Rs 63 in real life, so a till that keeps the half-rupee is a till
 * whose drawer can never reconcile: the screen says one number, the cash says
 * another, and the difference quietly accumulates all day.
 *
 * `total` becomes what the customer actually hands over. The half-rupee is not
 * discarded, it is recorded here — otherwise the books stop adding up, and a
 * shopkeeper checking the arithmetic on a receipt finds a gap with no name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Signed and small: rounding can only ever move a ticket by less
            // than a rupee, in either direction.
            $table->decimal('rounding_adjustment', 6, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('rounding_adjustment');
        });
    }
};
