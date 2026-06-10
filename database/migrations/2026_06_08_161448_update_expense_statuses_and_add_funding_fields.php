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
        DB::table('expenses')
            ->where('status', 'draft')
            ->update(['status' => 'registered']);

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('funded_amount', 15, 2)->default(0)->after('status');
            $table->timestamp('paid_at')->nullable()->after('distributed_at');

            $table->index('funded_amount');
            $table->index('paid_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_status_check');
            } else {
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_status_check');
            }

            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('registered', 'distributed', 'paid', 'cancelled'))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_funded_amount_check CHECK (funded_amount >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('expenses')
            ->where('status', 'registered')
            ->update(['status' => 'draft']);

        DB::table('expenses')
            ->where('status', 'paid')
            ->update(['status' => 'distributed']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_status_check');
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_funded_amount_check');
            } else {
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_status_check');
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_funded_amount_check');
            }

            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('draft', 'distributed', 'cancelled'))");
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['funded_amount']);
            $table->dropIndex(['paid_at']);
            $table->dropColumn(['funded_amount', 'paid_at']);
        });
    }
};
