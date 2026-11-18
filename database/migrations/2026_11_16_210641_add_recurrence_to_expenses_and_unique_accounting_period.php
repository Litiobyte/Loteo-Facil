<?php

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
        $duplicates = DB::table('accounting_periods')->select('year', 'month')
            ->selectRaw('COUNT(*) as total')->groupBy('year', 'month')
            ->havingRaw('COUNT(*) > 1')->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Existen períodos contables duplicados: '.$duplicates->toJson());
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->index();
            $table->date('recurrence_end_date')->nullable();
            $table->foreignId('parent_expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->index('parent_expense_id');
            $table->unique(['parent_expense_id', 'expense_date'], 'expenses_parent_date_unique');
        });
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->unique(['year', 'month'], 'accounting_periods_recurrence_year_month_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropUnique('accounting_periods_recurrence_year_month_unique');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_parent_date_unique');
            $table->dropForeign(['parent_expense_id']);
            $table->dropIndex(['parent_expense_id']);
            $table->dropIndex(['is_recurring']);
            $table->dropColumn(['parent_expense_id', 'is_recurring', 'recurrence_end_date']);
        });
    }
};
