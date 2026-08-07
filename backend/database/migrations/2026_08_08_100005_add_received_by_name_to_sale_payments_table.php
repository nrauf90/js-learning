<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who physically took the money, as free text.
     *
     * This is not `recorded_by`. That column holds the login that typed the
     * instalment in, and in a mohalla shop the two are routinely different
     * people: the owner's son works the counter on the owner's session, a
     * delivery boy collects at the customer's door and hands the notes over
     * later. When a payment is disputed the question is always "who did you
     * give it to", and only this column can answer it.
     *
     * Free text rather than a foreign key because most of these people have no
     * login at all — a name written in the notebook is exactly the record the
     * shopkeeper already keeps.
     */
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('received_by_name', 120)->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('received_by_name');
        });
    }
};
