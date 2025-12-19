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
        Schema::create('credit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('bureau', ['transunion', 'experian', 'equifax']);
            $table->string('account_name');
            $table->string('account_number')->nullable();
            $table->decimal('balance', 10, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('status')->nullable()->comment('e.g., Late Payment, Collection');
            $table->enum('dispute_status', ['pending', 'sent', 'deleted', 'verified'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('bureau');
            $table->index('dispute_status');
            $table->index(['client_id', 'bureau']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_items');
    }
};
