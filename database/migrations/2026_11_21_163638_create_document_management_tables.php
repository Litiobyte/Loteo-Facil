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
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_id')->default(1)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_visibility', 20)->default('admin');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'name']);
        });

        Schema::create('document_type_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 100)->index();
            $table->unique(['document_type_id', 'module_key']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_id')->default(1)->index();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('disk', 100);
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('visibility', 20)->default('admin');
            $table->text('description')->nullable();
            $table->date('document_date')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['community_id', 'document_type_id']);
        });

        Schema::create('documentables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->morphs('documentable');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_id', 'documentable_type', 'documentable_id'], 'documentables_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentables');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_type_modules');
        Schema::dropIfExists('document_types');
    }
};
