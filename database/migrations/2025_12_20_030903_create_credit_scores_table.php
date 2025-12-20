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
        Schema::create('credit_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->integer('transunion_score')->nullable();
            $table->integer('experian_score')->nullable();
            $table->integer('equifax_score')->nullable();
            $table->date('report_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_id');
            $table->index('report_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_scores');
    }
};
