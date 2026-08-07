<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relative path on the `public` disk, e.g. `catalog/products/RICE-001.jpg`.
     * Stored rather than a full URL so the app can move between disks (local
     * dev, S3) without rewriting every row.
     */
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('slug');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
