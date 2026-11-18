<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('mode')->default('lots');
            $table->decimal('square_meters_per_share', 18, 4)->default(800);
            $table->unsignedTinyInteger('share_precision')->default(0);
            $table->decimal('authorized_shares', 18, 4)->nullable();
            $table->timestamps();
        });
        DB::table('community_settings')->insert([
            'id' => 1, 'mode' => 'lots', 'square_meters_per_share' => '800',
            'share_precision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('community_setting_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('before');
            $table->json('after');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('share_holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('square_meters_per_share', 18, 4);
            $table->date('acquired_at');
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('share_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('share_holding_id')->constrained('share_holdings')->restrictOnDelete();
            $table->foreignId('counterparty_holding_id')->nullable()->constrained('share_holdings')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 100)->unique();
            $table->string('type');
            $table->decimal('quantity_before', 18, 4);
            $table->decimal('quantity_after', 18, 4);
            $table->json('context');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::table('partner_charges', function (Blueprint $table): void {
            $table->json('distribution_snapshot')->nullable();
            $table->string('calculation_type', 50)->change();
        });
        Schema::table('expenses', fn (Blueprint $table) => $table->string('distribution_type', 50)->change());
        $this->replaceChecks(true);
    }

    public function down(): void
    {
        $this->replaceChecks(false);
        Schema::table('partner_charges', fn (Blueprint $table) => $table->dropColumn('distribution_snapshot'));
        Schema::table('partner_charges', fn (Blueprint $table) => $table->string('calculation_type', 30)->change());
        Schema::table('expenses', fn (Blueprint $table) => $table->string('distribution_type', 30)->change());
        Schema::dropIfExists('share_movements');
        Schema::dropIfExists('share_holdings');
        Schema::dropIfExists('community_setting_revisions');
        Schema::dropIfExists('community_settings');
    }

    private function replaceChecks(bool $expanded): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        $values = "'equal_by_partner', 'proportional_by_hectares', 'manual'";
        if ($expanded) {
            $values .= ", 'proportional_by_shares', 'proportional_by_equivalent_area'";
        } else {
            foreach (['expenses' => 'distribution_type', 'partner_charges' => 'calculation_type'] as $table => $column) {
                if (DB::table($table)->whereIn($column, ['proportional_by_shares', 'proportional_by_equivalent_area'])->exists()) {
                    throw new RuntimeException('No se puede revertir mientras existan distribuciones por acciones.');
                }
            }
        }
        foreach (['expenses' => 'distribution_type', 'partner_charges' => 'calculation_type'] as $table => $column) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$table}_{$column}_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$values}))");
        }
    }
};
