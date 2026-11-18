<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_settings', function (Blueprint $table): void {
            $table->string('community_name')->default('Loteo Fácil')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('community_settings', function (Blueprint $table): void {
            $table->dropColumn('community_name');
        });
    }
};
