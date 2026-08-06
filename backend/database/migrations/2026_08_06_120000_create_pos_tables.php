<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_sale_id');
            $table->string('idempotency_key', 64);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('payment_method', 32)->default('cash');
            $table->string('status', 32)->default('completed');
            $table->timestamp('sold_at');
            $table->string('sync_source', 16)->default('online');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['user_id', 'client_sale_id']);
            $table->index(['sold_at', 'status']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->uuid('client_line_id');
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_refunded')->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['pos_sale_id', 'client_line_id']);
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_refund_id');
            $table->string('idempotency_key', 64);
            $table->decimal('total_refunded', 12, 2);
            $table->string('reason')->nullable();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['user_id', 'client_refund_id']);
        });

        Schema::create('pos_refund_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity_refunded');
            $table->decimal('amount_refunded', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_refund_lines');
        Schema::dropIfExists('pos_refunds');
        Schema::dropIfExists('pos_sale_lines');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_products');
    }
};
