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
        Schema::create('historical_external_identities', function (Blueprint $table) {
            $table->id();
            $table->string('community_scope', 64);
            $table->string('source', 100);
            $table->string('movement_type', 60);
            $table->string('external_id', 191);
            $table->string('identifiable_type', 100);
            $table->unsignedBigInteger('identifiable_id');
            $table->timestamps();
            $table->unique(
                ['community_scope', 'source', 'movement_type', 'external_id'],
                'historical_external_identity_unique',
            );
            $table->index(['identifiable_type', 'identifiable_id'], 'historical_identity_target_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Las identidades históricas no se eliminan mediante rollback.');
    }
};
