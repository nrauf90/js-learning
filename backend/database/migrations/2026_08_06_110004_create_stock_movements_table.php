<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            // initial | sale | refund | restock | adjustment
            $table->string('type', 16);

            // Signed: negative for sales, positive for restocks. `balance_after`
            // is denormalised so the history can be read back without replaying
            // every prior movement — the reason a count drifted is usually the
            // question, and that needs the running balance.
            $table->integer('quantity_delta');
            $table->integer('balance_after');

            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
