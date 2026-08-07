<?php

use App\Support\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teach the catalogue to sell loose goods.
 *
 * Half a Pakistani grocery is weighed out of a sack — atta, chawal, daal,
 * chini, ghee, sabzi — and until now every quantity in the system was an
 * integer, so none of it could be rung up at all.
 *
 * Quantities become decimals and prices gain two more decimal places, because
 * a price is now held per *base* unit: per gram, not per kilo. Namak at Rs
 * 40/kg is Rs 0.04/g, which rounds away entirely at two places.
 *
 * Nothing already in the database changes value. An existing product is sold by
 * the piece, its base unit is the piece, and a price "per piece" is already a
 * price per base unit — so the defaults below leave every existing row saying
 * exactly what it said before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // each | weight | volume — what kind of goods this is.
            $table->string('unit_type', 8)->default(Unit::TYPE_EACH)->after('name');
            // pc | g | ml — the unit `price` and `stock_quantity` are held in.
            $table->string('base_unit', 4)->default('pc')->after('unit_type');
            // pc | dozen | g | kg | ml | l — the unit the shopkeeper quotes in.
            $table->string('price_unit', 8)->default('pc')->after('base_unit');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 12, Unit::PRICE_DP)->change();
            $table->decimal('cost', 12, Unit::PRICE_DP)->nullable()->change();
            $table->decimal('stock_quantity', 12, Unit::QUANTITY_DP)->default(0)->change();
            $table->decimal('low_stock_threshold', 12, Unit::QUANTITY_DP)->default(0)->change();
        });

        // Snapshotted for the same reason name and price already are: a receipt
        // reprinted next year has to still read "250 g", even if the product has
        // since been switched to selling by the packet.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('unit_type', 8)->default(Unit::TYPE_EACH)->after('name');
            $table->string('base_unit', 4)->default('pc')->after('unit_type');
            $table->string('price_unit', 8)->default('pc')->after('base_unit');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_price', 12, Unit::PRICE_DP)->change();
            $table->decimal('unit_cost', 12, Unit::PRICE_DP)->nullable()->change();
            $table->decimal('quantity', 12, Unit::QUANTITY_DP)->change();
            $table->decimal('refunded_quantity', 12, Unit::QUANTITY_DP)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity_delta', 12, Unit::QUANTITY_DP)->change();
            $table->decimal('balance_after', 12, Unit::QUANTITY_DP)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('quantity_delta')->change();
            $table->integer('balance_after')->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
            $table->decimal('unit_cost', 10, 2)->nullable()->change();
            $table->integer('quantity')->change();
            $table->integer('refunded_quantity')->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'base_unit', 'price_unit']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
            $table->decimal('cost', 10, 2)->nullable()->change();
            $table->integer('stock_quantity')->default(0)->change();
            $table->integer('low_stock_threshold')->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'base_unit', 'price_unit']);
        });
    }
};
