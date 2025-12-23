<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('credit_items', function (Blueprint $table) {
            $table->text('payment_history')->nullable()->after('past_due');
            $table->string('payment_status')->nullable()->after('status'); // Separate from status
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_items', function (Blueprint $table) {
            $table->dropColumn(['payment_history', 'payment_status']);
        });
    }
};
