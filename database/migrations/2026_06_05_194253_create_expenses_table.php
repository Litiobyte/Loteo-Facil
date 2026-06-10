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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('distribution_type', 30);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('expense_category_id');
            $table->index('status');
            $table->index('distribution_type');
            $table->index('expense_date');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('draft', 'distributed', 'cancelled'))");
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_distribution_type_check CHECK (distribution_type IN ('equal_by_partner', 'proportional_by_hectares', 'manual'))");
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
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_amount_positive_check');
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_status_check');
                DB::statement('ALTER TABLE expenses DROP CHECK expenses_distribution_type_check');
            } else {
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_amount_positive_check');
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_status_check');
                DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_distribution_type_check');
            }
        }

        Schema::dropIfExists('expenses');
    }
};
