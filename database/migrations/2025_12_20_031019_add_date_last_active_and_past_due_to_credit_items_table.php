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
            $table->date('date_last_active')->nullable()->after('date_opened');
            $table->decimal('past_due', 10, 2)->nullable()->after('monthly_pay');
            $table->date('date_reported')->nullable()->after('date_last_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_items', function (Blueprint $table) {
            $table->dropColumn(['date_last_active', 'past_due', 'date_reported']);
        });
    }
};
