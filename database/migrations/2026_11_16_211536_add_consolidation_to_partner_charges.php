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
        if (DB::getDriverName() === 'mysql') {
            Schema::table('partner_charges', function (Blueprint $table) {
                $table->dropForeign(['expense_id']);
            });
        }
        Schema::table('partner_charges', function (Blueprint $table) {
            $table->foreignId('expense_id')->nullable()->change();
            $table->string('charge_type')->default('distribution')->index();
            $table->smallInteger('consolidation_year')->nullable();
            $table->smallInteger('consolidation_month')->nullable();
            $table->date('imputation_date')->nullable()->index();
            $table->index(['consolidation_year', 'consolidation_month']);
            $table->unique(['propietario_id', 'consolidation_year', 'consolidation_month'], 'charge_owner_consolidation_period_unique');
        });
        if (DB::getDriverName() === 'mysql') {
            Schema::table('partner_charges', function (Blueprint $table) {
                $table->foreign('expense_id')->references('id')->on('expenses')->restrictOnDelete();
            });
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_status_check');
            DB::statement("ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_status_check CHECK (status IN ('pending', 'partial', 'paid', 'cancelled', 'consolidated'))");
        }
        Schema::create('partner_charge_consolidation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_charge_id')->constrained('partner_charges')->restrictOnDelete();
            $table->foreignId('source_charge_id')->constrained('partner_charges')->restrictOnDelete();
            $table->decimal('transferred_amount', 15, 2);
            $table->unique(['consolidated_charge_id', 'source_charge_id'], 'charge_consolidation_link_unique');
            $table->unique('source_charge_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('partner_charges')->whereNull('expense_id')->exists() || DB::table('partner_charges')->where('status', 'consolidated')->exists()) {
            throw new RuntimeException('No se puede revertir la consolidación con datos existentes.');
        }
        Schema::dropIfExists('partner_charge_consolidation_links');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE partner_charges DROP CHECK partner_charges_status_check');
            DB::statement("ALTER TABLE partner_charges ADD CONSTRAINT partner_charges_status_check CHECK (status IN ('pending', 'partial', 'paid', 'cancelled'))");
            Schema::table('partner_charges', function (Blueprint $table) {
                $table->dropForeign(['expense_id']);
            });
        }
        Schema::table('partner_charges', function (Blueprint $table) {
            $table->dropUnique('charge_owner_consolidation_period_unique');
            $table->dropIndex(['consolidation_year', 'consolidation_month']);
            $table->dropIndex(['charge_type']);
            $table->dropIndex(['imputation_date']);
            $table->dropColumn(['charge_type', 'consolidation_year', 'consolidation_month', 'imputation_date']);
            $table->foreignId('expense_id')->nullable(false)->change();
        });
        if (DB::getDriverName() === 'mysql') {
            Schema::table('partner_charges', function (Blueprint $table) {
                $table->foreign('expense_id')->references('id')->on('expenses')->restrictOnDelete();
            });
        }
    }
};
