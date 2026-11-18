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
        Schema::create('share_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_setting_id')->constrained('community_settings')->restrictOnDelete();
            $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
            $table->foreignId('share_holding_id')->nullable()->constrained('share_holdings')->restrictOnDelete();
            $table->foreignId('corrects_contribution_id')->nullable()->constrained('share_contributions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->boolean('opening_marker')->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('reference_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('operation_key', 80)->unique();
            $table->json('context');
            $table->timestamps();
            $table->unique(['community_setting_id', 'propietario_id', 'opening_marker'], 'share_contributions_opening_unique');
            $table->index(['propietario_id', 'type']);
        });
        $this->changeAcquisitionDateNullability(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('share_holdings')->whereNull('acquired_at')->exists()) {
            throw new RuntimeException('No se puede revertir sin inventar fechas de adquisiciones históricas.');
        }
        Schema::dropIfExists('share_contributions');
        $this->changeAcquisitionDateNullability(false);
    }

    /**
     * SQLite reconstruye el padre referenciado. La verificación explícita permite
     * reiniciar, antes del commit, el contador diferido de referencias al padre reemplazado.
     */
    private function changeAcquisitionDateNullability(bool $nullable): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        $wasDeferred = $sqlite && (bool) DB::scalar('PRAGMA defer_foreign_keys');
        if ($sqlite) {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        try {
            $change = function () use ($nullable, $sqlite, $wasDeferred): void {
                Schema::table('share_holdings', fn (Blueprint $table) => $table->date('acquired_at')->nullable($nullable)->change());
                if ($sqlite && DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('La migración no puede dejar referencias históricas inválidas.');
                }
                if ($sqlite) {
                    DB::statement('PRAGMA defer_foreign_keys = OFF');
                    if ($wasDeferred) {
                        DB::statement('PRAGMA defer_foreign_keys = ON');
                    }
                }
            };
            if ($sqlite) {
                DB::transaction($change);
            } else {
                $change();
            }
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA defer_foreign_keys = '.($wasDeferred ? 'ON' : 'OFF'));
            }
        }
    }
};
