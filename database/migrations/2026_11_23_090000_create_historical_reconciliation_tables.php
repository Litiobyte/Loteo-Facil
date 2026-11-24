<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('propietario_id')->constrained('propietarios')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->date('cutoff_date')->nullable();
            $table->decimal('expected_debt', 12, 2)->nullable();
            $table->decimal('calculated_debt', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('final_calculated_debt', 12, 2)->nullable();
            $table->string('status', 30);
            $table->json('summary')->nullable();
            $table->string('source');
            $table->string('reference')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['propietario_id', 'kind']);
        });

        Schema::table('collections', function (Blueprint $table): void {
            $table->string('evidence_status', 20)->default('documented')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('evidence_status');
        });

        Schema::dropIfExists('historical_reconciliations');
    }
};
