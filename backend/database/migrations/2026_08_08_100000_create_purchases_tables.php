<?php

use App\Support\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop paid for its stock.
 *
 * Until now `products.cost` was only ever whatever someone typed into the
 * catalogue by hand, so `sale_items.unit_cost` was usually null and every
 * profit figure the app reported read close to zero. Recording the sack of
 * atta as it comes off the delivery van is the only thing that fixes that at
 * the source: the cost is captured where the money actually changed hands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // The supplier picker is "this shop's wholesalers, by name", which
            // is the only way this table is ever read.
            $table->index(['user_id', 'name']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A wholesaler who stops trading must not take the shop's own
            // purchase history with them, so the link is severed, not cascaded.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            // Assigned from the row id straight after insert, exactly like a
            // sale's reference: unique and ordered without a per-user counter
            // to race on. Nullable only for the gap between insert and update.
            $table->string('reference', 32)->nullable()->unique();

            // The wholesaler's own bill number, which is what the shopkeeper
            // matches against when the supplier queries a payment.
            $table->string('invoice_number', 64)->nullable();

            $table->date('purchase_date');

            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            // Stock arrives long before it is paid for in a Pakistani grocery —
            // the van leaves the goods and the money follows on the next round.
            $table->decimal('amount_paid', 12, 2)->default(0);
            // paid | partial | unpaid
            $table->string('payment_status', 16)->default('unpaid');

            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purchase_date']);
            $table->index(['user_id', 'payment_status']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            // Deleting a product must not erase what the shop paid for it, so
            // the line keeps its snapshot and simply loses the link.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Snapshotted for the same reason a sale line is: renaming atta or
            // switching it from kilos to packets cannot be allowed to rewrite
            // last month's delivery note.
            $table->string('name', 160);
            $table->string('unit_type', 8)->default(Unit::TYPE_EACH);
            $table->string('base_unit', 4)->default('pc');
            $table->string('price_unit', 8)->default('pc');

            // Both in BASE units, like everything else under the API: grams
            // received and rupees per gram. The shopkeeper types "50 kg at
            // Rs 180" and PurchaseService divides that down once, on the way in.
            $table->decimal('quantity', 12, Unit::QUANTITY_DP);
            $table->decimal('unit_cost', 12, Unit::PRICE_DP);
            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
