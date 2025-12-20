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
        Schema::create('personal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('bureau', ['transunion', 'experian', 'equifax']);
            $table->string('name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('current_address')->nullable();
            $table->string('previous_address')->nullable();
            $table->string('employer')->nullable();
            $table->date('date_reported')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'bureau']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_profiles');
    }
};
