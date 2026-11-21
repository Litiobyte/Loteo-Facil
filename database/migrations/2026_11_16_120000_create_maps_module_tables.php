<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('etapa_id')->constrained('etapas')->cascadeOnDelete();
            $table->string('filename');
            $table->string('original_path');
            $table->string('status')->default('reviewing'); // reviewing|published|discarded
            $table->json('summary')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('map_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('etapa_id')->constrained('etapas')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color', 9)->default('#e11d48');
            $table->unsignedTinyInteger('opacity')->default(60); // 0-100
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['etapa_id', 'slug']);
        });

        Schema::create('maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('etapa_id')->unique()->constrained('etapas')->cascadeOnDelete();
            $table->foreignId('published_import_id')->nullable()->constrained('map_imports')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('map_geometries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_import_id')->constrained('map_imports')->cascadeOnDelete();
            $table->foreignId('map_layer_id')->constrained('map_layers')->cascadeOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->json('geometry'); // GeoJSON geometry (EPSG:4326)
            $table->json('properties')->nullable(); // name, description, identifier, source_folder, warnings
            $table->string('normalized_identifier')->nullable()->index();
            $table->string('association_status')->default('none'); // exact|probable|ambiguous|none
            $table->string('association_method')->default('auto'); // auto|manual|carried
            $table->boolean('is_confirmed')->default(false);
            $table->unsignedTinyInteger('match_confidence')->nullable(); // 0-100
            $table->decimal('area_m2', 14, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_geometries');
        Schema::dropIfExists('maps');
        Schema::dropIfExists('map_layers');
        Schema::dropIfExists('map_imports');
    }
};
