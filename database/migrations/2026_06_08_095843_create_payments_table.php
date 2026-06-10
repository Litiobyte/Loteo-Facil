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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('applied_amount', 10, 2)->default(0);
            $table->decimal('unapplied_amount', 10, 2)->default(0);
            $table->date('payment_date');
            $table->string('payment_method', 30);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending_application');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('propietario_id');
            $table->index('payment_date');
            $table->index('status');
            $table->index('created_by');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_applied_amount_non_negative_check CHECK (applied_amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_unapplied_amount_non_negative_check CHECK (unapplied_amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_applied_amount_lte_amount_check CHECK (applied_amount <= amount)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending_application', 'partially_applied', 'fully_applied', 'cancelled'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (payment_method IN ('efectivo', 'transferencia', 'cheque', 'otro'))");
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
                DB::statement('ALTER TABLE payments DROP CHECK payments_amount_positive_check');
                DB::statement('ALTER TABLE payments DROP CHECK payments_applied_amount_non_negative_check');
                DB::statement('ALTER TABLE payments DROP CHECK payments_unapplied_amount_non_negative_check');
                DB::statement('ALTER TABLE payments DROP CHECK payments_applied_amount_lte_amount_check');
                DB::statement('ALTER TABLE payments DROP CHECK payments_status_check');
                DB::statement('ALTER TABLE payments DROP CHECK payments_method_check');
            } else {
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_positive_check');
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_applied_amount_non_negative_check');
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_unapplied_amount_non_negative_check');
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_applied_amount_lte_amount_check');
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check');
                DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check');
            }
        }

        Schema::dropIfExists('payments');
    }
};
