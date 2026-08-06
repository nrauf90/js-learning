<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 160);
            // SKU and barcode are both optional and both scoped per user. The
            // unique indexes include user_id so two shops can use the same
            // code; NULLs are exempt from uniqueness, so untagged products are
            // unaffected.
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();

            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->nullable();

            // `track_stock` off means the product is sellable without a count —
            // services, or anything weighed/made to order.
            $table->boolean('track_stock')->default(true);
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'sku']);
            $table->unique(['user_id', 'barcode']);
            // Covers the till's two hot paths: listing a shop's active products
            // and looking one up while typing.
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
