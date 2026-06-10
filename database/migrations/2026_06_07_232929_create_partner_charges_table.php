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
        Schema::create('partner_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2);
            $table->string('status', 20)->default('pending');
            $table->date('due_date')->nullable();
            $table->text('description')->nullable();

            $table->string('calculation_type', 30);
            $table->decimal('partner_hectares_at_moment', 10, 4)->nullable();
            $table->decimal('total_hectares_at_moment', 10, 4)->nullable();
            $table->decimal('percentage_applied', 5, 2)->nullable();
            $table->text('calculation_notes')->nullable();
            $table->timestamps();

            $table->unique(['expense_id', 'propietario_id'], 'partner_charges_expense_propietario_unique');
            $table->index('expense_id');
            $table->index('propietario_id');
            $table->index('status');
            $table->index('due_date');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_paid_amount_non_negative_check CHECK (paid_amount >= 0)');
            DB::statement('ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_remaining_amount_non_negative_check CHECK (remaining_amount >= 0)');
            DB::statement('ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_paid_amount_lte_amount_check CHECK (paid_amount <= amount)');
            DB::statement("ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_status_check CHECK (status IN ('pending', 'partial', 'paid', 'cancelled'))");
            DB::statement("ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_calculation_type_check CHECK (calculation_type IN ('equal_by_partner', 'proportional_by_hectares', 'manual'))");
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
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_amount_positive_check');
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_paid_amount_non_negative_check');
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_remaining_amount_non_negative_check');
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_paid_amount_lte_amount_check');
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_status_check');
                DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_calculation_type_check');
            } else {
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_amount_positive_check');
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_paid_amount_non_negative_check');
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_remaining_amount_non_negative_check');
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_paid_amount_lte_amount_check');
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_status_check');
                DB::statement('ALTER TABLE partner_charges DROP CONSTRAINT IF EXISTS partner_charges_calculation_type_check');
            }
        }

        Schema::dropIfExists('partner_charges');
    }
};
