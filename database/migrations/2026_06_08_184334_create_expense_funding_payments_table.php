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
        Schema::create('expense_funding_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->boolean('is_void')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expense_id');
            $table->index('payment_date');
            $table->index('is_void');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE expense_funding_payments ADD CONSTRAINT expense_funding_payments_amount_positive_check CHECK (amount > 0)');
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
                DB::statement('ALTER TABLE expense_funding_payments DROP CHECK expense_funding_payments_amount_positive_check');
            } else {
                DB::statement('ALTER TABLE expense_funding_payments DROP CONSTRAINT IF EXISTS expense_funding_payments_amount_positive_check');
            }
        }

        Schema::dropIfExists('expense_funding_payments');
    }
};
