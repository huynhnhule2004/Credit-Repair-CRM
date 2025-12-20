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
            $table->string('account_type')->nullable()->after('account_number')->comment('e.g., Credit Card, Loan, Mortgage');
            $table->date('date_opened')->nullable()->after('account_type');
            $table->decimal('high_limit', 10, 2)->nullable()->after('balance')->comment('Credit limit or loan amount');
            $table->decimal('monthly_pay', 10, 2)->nullable()->after('high_limit')->comment('Monthly payment amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_items', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'date_opened', 'high_limit', 'monthly_pay']);
        });
    }
};
