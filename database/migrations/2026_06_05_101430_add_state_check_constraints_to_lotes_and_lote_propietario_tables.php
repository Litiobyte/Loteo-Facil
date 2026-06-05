<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE lotes ADD CONSTRAINT lotes_estado_check CHECK (estado IN ('disponible', 'reservado', 'vendido'))");
        DB::statement("ALTER TABLE lote_propietario ADD CONSTRAINT lote_propietario_status_check CHECK (status IN ('active', 'inactive', 'unassigned'))");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE lotes DROP CHECK lotes_estado_check');
            DB::statement('ALTER TABLE lote_propietario DROP CHECK lote_propietario_status_check');

            return;
        }

        DB::statement('ALTER TABLE lotes DROP CONSTRAINT IF EXISTS lotes_estado_check');
        DB::statement('ALTER TABLE lote_propietario DROP CONSTRAINT IF EXISTS lote_propietario_status_check');
    }
};
