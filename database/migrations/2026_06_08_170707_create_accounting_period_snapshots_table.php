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
        Schema::create('accounting_period_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->decimal('total_charges', 15, 2)->default(0);
            $table->decimal('total_payments', 15, 2)->default(0);
            $table->decimal('total_allocations', 15, 2)->default(0);
            $table->decimal('pending_balance', 15, 2)->default(0);
            $table->decimal('credit_balance', 15, 2)->default(0);
            $table->decimal('overdue_1_30', 15, 2)->default(0);
            $table->decimal('overdue_31_60', 15, 2)->default(0);
            $table->decimal('overdue_61_90', 15, 2)->default(0);
            $table->decimal('overdue_90_plus', 15, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('accounting_period_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_period_snapshots');
    }
};
