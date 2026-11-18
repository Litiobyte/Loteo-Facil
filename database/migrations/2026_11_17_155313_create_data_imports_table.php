<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('community_setting_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('status')->default('uploaded')->index();
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->string('duplicate_mode')->default('skip');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('raw');
            $table->json('data')->nullable();
            $table->string('action')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->json('changes')->nullable();
            $table->json('errors')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
            $table->unique(['data_import_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_import_rows');
        Schema::dropIfExists('data_imports');
    }
};
