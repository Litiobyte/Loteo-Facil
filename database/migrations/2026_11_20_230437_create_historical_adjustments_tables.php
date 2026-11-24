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
        Schema::create('historical_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
            $table->string('category', 40);
            $table->decimal('amount', 15, 2);
            $table->string('direction', 20);
            $table->date('effective_date')->nullable();
            $table->unsignedSmallInteger('effective_year')->nullable();
            $table->unsignedTinyInteger('effective_month')->nullable();
            $table->string('date_precision', 10);
            $table->timestamp('registered_at');
            $table->string('source');
            $table->string('reference')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->boolean('from_import')->default(false);
            $table->string('evidence_status', 30);
            $table->timestamps();
            $table->index(['propietario_id', 'category']);
        });
        Schema::create('historical_adjustment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historical_adjustment_id')->constrained(indexName: 'haa_adjustment_fk')->restrictOnDelete();
            $table->foreignId('partner_charge_id')->constrained(indexName: 'haa_charge_fk')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('effective_date');
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['partner_charge_id', 'historical_adjustment_id'], 'historical_alloc_charge_adjustment_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('No se eliminan ajustes históricos mediante rollback.');
    }
};
