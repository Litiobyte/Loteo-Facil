<?php

use App\Models\Expense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('historical_distribution_blocked')->default(false);
        });
        $ids = DB::table('historical_external_identities')
            ->where('movement_type', 'expense')
            ->where('identifiable_type', Expense::class)
            ->pluck('identifiable_id');
        DB::table('expenses')->whereIn('id', $ids)->where('status', 'registered')
            ->update(['historical_distribution_blocked' => true]);
        DB::table('expenses')->where('status', 'registered')->whereNotNull('notes')
            ->orderBy('id')->chunkById(500, function ($expenses): void {
                foreach ($expenses as $expense) {
                    $notes = json_decode($expense->notes, true);
                    if (is_array($notes) && ($notes['from_import'] ?? false) === true && ($notes['mode'] ?? null) === 'register_only') {
                        DB::table('expenses')->where('id', $expense->id)->update(['historical_distribution_blocked' => true]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('No se puede retirar la protección de distribución histórica.');
    }
};
