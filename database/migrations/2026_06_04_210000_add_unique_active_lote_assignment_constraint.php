<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX lote_propietario_unique_active_lote_idx ON lote_propietario (lote_id) WHERE status = 'active'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX lote_propietario_unique_active_lote_idx ON lote_propietario (lote_id) WHERE status = 'active'");

            return;
        }

        if ($driver === 'mysql') {
            Schema::table('lote_propietario', function (Blueprint $table): void {
                $table->unsignedBigInteger('active_lote_id')->nullable()->storedAs("CASE WHEN status = 'active' THEN lote_id ELSE NULL END");
                $table->unique('active_lote_id', 'lote_propietario_unique_active_lote_idx');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS lote_propietario_unique_active_lote_idx');

            return;
        }

        if ($driver === 'mysql') {
            Schema::table('lote_propietario', function (Blueprint $table): void {
                $table->dropUnique('lote_propietario_unique_active_lote_idx');
                $table->dropColumn('active_lote_id');
            });
        }
    }
};
