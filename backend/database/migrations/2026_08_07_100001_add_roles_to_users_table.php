<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin       — platform operator (mirrors the existing is_admin flag)
            // shop_admin  — owns a shop, its catalogue, staff and takings
            // staff       — works the till, nothing else unless granted below
            //
            // Defaults to shop_admin because every account that exists today
            // signed up to run its own shop: the products, sales and cash
            // entries in those rows belong to the person who registered.
            $table->string('role', 16)->default('shop_admin')->after('is_admin');

            // Which shop a staff account works for. Null for platform admins
            // and for shop admins before they have filled in their shop.
            $table->foreignId('shop_id')->nullable()->after('role')
                ->constrained('shops')->nullOnDelete();

            // Per-user grant a shop admin can hand out. Without it, staff can
            // sell but cannot touch the catalogue or stock.
            $table->boolean('can_manage_products')->default(false)->after('shop_id');

            $table->index(['shop_id', 'role']);
        });

        // Existing platform admins keep the matching role so the two never
        // disagree — is_admin stays the authority, role mirrors it.
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'role']);
            $table->dropConstrainedForeignId('shop_id');
            $table->dropColumn(['role', 'can_manage_products']);
        });
    }
};
