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
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::create('collections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->decimal('applied_amount', 10, 2)->default(0);
                $table->decimal('unapplied_amount', 10, 2)->default(0);
                $table->date('collection_date');
                $table->string('collection_method', 30);
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('pending_application');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('propietario_id');
                $table->index('collection_date');
                $table->index('status');
                $table->index('created_by');
            });

            DB::statement('INSERT INTO collections (id, propietario_id, amount, applied_amount, unapplied_amount, collection_date, collection_method, reference, notes, status, created_by, created_at, updated_at) SELECT id, propietario_id, amount, applied_amount, unapplied_amount, payment_date, payment_method, reference, notes, status, created_by, created_at, updated_at FROM payments');

            Schema::create('collection_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('collection_id')->constrained('collections')->restrictOnDelete();
                $table->foreignId('partner_charge_id')->constrained('partner_charges')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->timestamp('allocated_at')->useCurrent();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['collection_id', 'partner_charge_id'], 'collection_allocations_collection_charge_unique');
                $table->index('collection_id');
                $table->index('partner_charge_id');
            });

            DB::statement('INSERT INTO collection_allocations (id, collection_id, partner_charge_id, amount, allocated_at, created_by, created_at, updated_at) SELECT id, payment_id, partner_charge_id, amount, allocated_at, created_by, created_at, updated_at FROM payment_allocations');

            Schema::dropIfExists('payment_allocations');
            Schema::dropIfExists('payments');

            Schema::table('accounting_period_snapshots', function (Blueprint $table): void {
                $table->renameColumn('total_payments', 'total_collections');
            });
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_applied_amount_non_negative_check CHECK (applied_amount >= 0)');
            DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_unapplied_amount_non_negative_check CHECK (unapplied_amount >= 0)');
            DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_applied_amount_lte_amount_check CHECK (applied_amount <= amount)');
            DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_status_check CHECK (status IN ('pending_application', 'partially_applied', 'fully_applied', 'cancelled'))");
            DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_method_check CHECK (collection_method IN ('efectivo', 'transferencia', 'cheque', 'otro'))");
            DB::statement('ALTER TABLE collection_allocations ADD CONSTRAINT collection_allocations_amount_positive_check CHECK (amount > 0)');
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
                DB::statement('ALTER TABLE collection_allocations DROP CHECK collection_allocations_amount_positive_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_amount_positive_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_applied_amount_non_negative_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_unapplied_amount_non_negative_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_applied_amount_lte_amount_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_status_check');
                DB::statement('ALTER TABLE collections DROP CHECK collections_method_check');
            } else {
                DB::statement('ALTER TABLE collection_allocations DROP CONSTRAINT IF EXISTS collection_allocations_amount_positive_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_amount_positive_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_applied_amount_non_negative_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_unapplied_amount_non_negative_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_applied_amount_lte_amount_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_status_check');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_method_check');
            }
        }

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('propietario_id')->constrained('propietarios')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->decimal('applied_amount', 10, 2)->default(0);
                $table->decimal('unapplied_amount', 10, 2)->default(0);
                $table->date('payment_date');
                $table->string('payment_method', 30);
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('pending_application');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('propietario_id');
                $table->index('payment_date');
                $table->index('status');
                $table->index('created_by');
            });

            DB::statement('INSERT INTO payments (id, propietario_id, amount, applied_amount, unapplied_amount, payment_date, payment_method, reference, notes, status, created_by, created_at, updated_at) SELECT id, propietario_id, amount, applied_amount, unapplied_amount, collection_date, collection_method, reference, notes, status, created_by, created_at, updated_at FROM collections');

            Schema::create('payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
                $table->foreignId('partner_charge_id')->constrained('partner_charges')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->timestamp('allocated_at')->useCurrent();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['payment_id', 'partner_charge_id'], 'payment_allocations_payment_charge_unique');
                $table->index('payment_id');
                $table->index('partner_charge_id');
            });

            DB::statement('INSERT INTO payment_allocations (id, payment_id, partner_charge_id, amount, allocated_at, created_by, created_at, updated_at) SELECT id, collection_id, partner_charge_id, amount, allocated_at, created_by, created_at, updated_at FROM collection_allocations');

            Schema::dropIfExists('collection_allocations');
            Schema::dropIfExists('collections');

            Schema::table('accounting_period_snapshots', function (Blueprint $table): void {
                $table->renameColumn('total_collections', 'total_payments');
            });
        });

        if ($driver !== 'sqlite') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_applied_amount_non_negative_check CHECK (applied_amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_unapplied_amount_non_negative_check CHECK (unapplied_amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_applied_amount_lte_amount_check CHECK (applied_amount <= amount)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending_application', 'partially_applied', 'fully_applied', 'cancelled'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (payment_method IN ('efectivo', 'transferencia', 'cheque', 'otro'))");
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check CHECK (amount > 0)');
        }
    }
};
