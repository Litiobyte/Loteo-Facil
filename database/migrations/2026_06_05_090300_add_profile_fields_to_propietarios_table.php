<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('propietarios', function (Blueprint $table) {
            $table->string('nombre')->after('user_id');
            $table->string('apellido')->after('nombre');
            $table->string('rut')->unique()->after('apellido');
            $table->string('telefono')->nullable()->after('rut');
            $table->string('direccion')->nullable()->after('telefono');
            $table->foreignId('region_id')->nullable()->after('direccion')->constrained('regiones')->nullOnDelete();
            $table->foreignId('comuna_id')->nullable()->after('region_id')->constrained('comunas')->nullOnDelete();
            $table->string('nacionalidad')->nullable()->after('comuna_id');
            $table->string('profesion')->nullable()->after('nacionalidad');
            $table->string('estado_civil')->nullable()->after('profesion');
            $table->string('email')->unique()->after('estado_civil');
        });
    }

    public function down(): void
    {
        Schema::table('propietarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comuna_id');
            $table->dropConstrainedForeignId('region_id');
            $table->dropUnique('propietarios_rut_unique');
            $table->dropUnique('propietarios_email_unique');
            $table->dropColumn([
                'nombre',
                'apellido',
                'rut',
                'telefono',
                'direccion',
                'nacionalidad',
                'profesion',
                'estado_civil',
                'email',
            ]);
        });
    }
};
