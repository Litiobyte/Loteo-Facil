<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('partner_charge_id')->constrained('partner_charges')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('allocated_at')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_id', 'partner_charge_id'], 'payment_allocations_payment_charge_unique');
            $table->index('payment_id');
            $table->index('partner_charge_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check CHECK (amount > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE payment_allocations DROP CHECK payment_allocations_amount_positive_check');
            } else {
                DB::statement('ALTER TABLE payment_allocations DROP CONSTRAINT IF EXISTS payment_allocations_amount_positive_check');
            }
        }

        Schema::dropIfExists('payment_allocations');
    }
};
