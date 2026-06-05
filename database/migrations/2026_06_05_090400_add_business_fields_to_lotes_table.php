<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->decimal('hectareas', 12, 2)->default(0)->change();
            $table->string('estado')->default('disponible')->after('codigo');
            $table->unsignedBigInteger('metros_cuadrados')->default(0)->after('estado');
            $table->foreignId('etapa_id')->nullable()->after('metros_cuadrados')->constrained('etapas')->nullOnDelete();
            $table->unsignedBigInteger('valor_lote')->default(0)->after('etapa_id');
            $table->text('notas')->nullable()->after('valor_lote');
        });

        DB::table('lotes')
            ->whereNotNull('hectareas')
            ->update([
                'metros_cuadrados' => DB::raw('CAST(hectareas * 10000 AS INTEGER)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('etapa_id');
            $table->dropColumn([
                'estado',
                'metros_cuadrados',
                'valor_lote',
                'notas',
            ]);
        });
    }
};
