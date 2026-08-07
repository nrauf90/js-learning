<?php

use App\Support\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three ways a grocery loses stock without selling it.
 *
 * Sabzi wilts, doodh turns and bread goes hard — every shop throws something
 * away most days, and until now that loss left the shelf without leaving a
 * number anywhere. `reason` says what happened to it and `cost_value` says what
 * it was worth, so a month of wastage can be read in rupees rather than in a
 * pile of free-text notes.
 *
 * Expiry is the same loss caught a week earlier: a date on the row and a flag
 * saying the shopkeeper wants to be warned about it.
 *
 * Pack size is the other half of a Pakistani counter — a peti of 24 Coke is
 * bought whole and sold one bottle at a time. The pack is a *buying* fact only:
 * nothing prices off it, so a wrong pack size can never mis-charge a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Held in base units, like every other quantity on this row, so the
            // peti of 24 and the 50 kg bori are the same column. Default 1 means
            // "no pack" — every existing product keeps saying what it said.
            $table->decimal('pack_size', 12, Unit::QUANTITY_DP)->default(1)->after('low_stock_threshold');
            $table->string('pack_label', 32)->nullable()->after('pack_size');

            $table->date('expiry_date')->nullable()->after('pack_label');
            // Off by default: most of the shelf — atta, soap, batteries — has no
            // date worth chasing, and a warning shown on everything is a warning
            // the shopkeeper learns to ignore.
            $table->boolean('track_expiry')->default(false)->after('expiry_date');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // damaged | expired | spoiled | theft | sample | count_correction.
            // Typed rather than free text because the note cannot be totalled:
            // "phaans gaya", "gir gaya" and "kharab" are the same loss.
            $table->string('reason', 32)->nullable()->after('type');

            // Frozen at the moment of the write. Reading it back off the product
            // later would revalue last month's spoilage at this month's cost,
            // and the P&L would move every time a supplier raised a price.
            $table->decimal('cost_value', 12, 2)->nullable()->after('balance_after');

            $table->index(['user_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'reason']);
            $table->dropColumn(['reason', 'cost_value']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['pack_size', 'pack_label', 'expiry_date', 'track_expiry']);
        });
    }
};
