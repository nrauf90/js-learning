<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Idempotency key minted by the till before the sale is sent. A
            // queued offline sale can be replayed any number of times — the
            // network dropping mid-POST is the normal case, not an edge case —
            // and this unique index is what stops the replay creating a second
            // sale and decrementing stock twice.
            $table->uuid('client_uuid')->nullable()->after('reference');

            // Sales that were rung up while the till had no connection. Kept as
            // a flag rather than inferred from client_uuid because it changes
            // how stock conflicts are handled on sync.
            $table->boolean('is_offline')->default(false)->after('client_uuid');

            $table->decimal('refunded_amount', 12, 2)->default(0)->after('total');

            $table->unique(['user_id', 'client_uuid']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // Partial refunds return some units of a line, not the whole line.
            $table->integer('refunded_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_uuid']);
            $table->dropColumn(['client_uuid', 'is_offline', 'refunded_amount']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('refunded_quantity');
        });
    }
};
