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
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('open');
            $table->string('close_folio', 30)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month'], 'accounting_periods_year_month_unique');
            $table->unique('close_folio');
            $table->index('status');
            $table->index('closed_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_month_range_check CHECK (month >= 1 AND month <= 12)');
            DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open', 'closed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE accounting_periods DROP CHECK accounting_periods_month_range_check');
                DB::statement('ALTER TABLE accounting_periods DROP CHECK accounting_periods_status_check');
            } else {
                DB::statement('ALTER TABLE accounting_periods DROP CONSTRAINT IF EXISTS accounting_periods_month_range_check');
                DB::statement('ALTER TABLE accounting_periods DROP CONSTRAINT IF EXISTS accounting_periods_status_check');
            }
        }

        Schema::dropIfExists('accounting_periods');
    }
};
