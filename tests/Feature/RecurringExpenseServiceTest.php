<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Expenses\Services\RecurringExpenseService;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecurringExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seriesData(array $overrides = []): array
    {
        return array_merge([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'title' => 'Cuota mensual',
            'amount' => 100,
            'expense_date' => '2024-01-31',
            'due_date' => '2024-01-31',
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'is_recurring' => true,
            'recurrence_end_date' => '2024-04-30',
        ], $overrides);
    }

    public function test_series_anchors_months_to_original_date_and_retry_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 31));
        $service = app(RecurringExpenseService::class);
        $head = $service->createRecurringSeries($this->seriesData());
        $service->createRecurringSeries(['head_id' => $head->id]);
        $children = Expense::query()->where('parent_expense_id', $head->id)->orderBy('expense_date')->get();
        $this->assertSame(['2024-02-29', '2024-03-31', '2024-04-30'], $children->map(fn ($child) => $child->expense_date->toDateString())->all());
        $this->assertSame(['2024-02-29', '2024-03-31', '2024-04-30'], $children->map(fn ($child) => $child->due_date->toDateString())->all());
        $this->assertTrue($children->every(fn ($child) => $child->status === ExpenseStatus::Registered && $child->is_recurring));
    }

    public function test_maximum_twelve_and_rollback_when_exceeded(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 31));
        $service = app(RecurringExpenseService::class);
        $data = $this->seriesData(['recurrence_end_date' => '2024-12-31', 'due_date' => null]);
        $head = $service->createRecurringSeries($data);
        $this->assertSame(12, Expense::query()->count());
        $this->assertSame(0, Expense::query()->where('parent_expense_id', $head->id)->whereNotNull('due_date')->count());
        try {
            $service->createRecurringSeries(array_merge($data, ['recurrence_end_date' => '2025-01-31']));
            $this->fail('Debió rechazar la instancia 13.');
        } catch (DomainException) {
            $this->assertSame(12, Expense::query()->count());
        }
    }

    public function test_failed_child_validation_rolls_back_series(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 30));
        $data = $this->seriesData(['expense_date' => '2024-01-30', 'due_date' => '2024-01-31', 'recurrence_end_date' => '2024-04-30']);
        Expense::creating(function (Expense $expense): void {
            if ($expense->parent_expense_id !== null && $expense->expense_date->month === 3) {
                throw new DomainException('Fallo simulado.');
            }
        });
        try {
            app(RecurringExpenseService::class)->createRecurringSeries($data);
            $this->fail('Debió fallar.');
        } catch (DomainException) {
            $this->assertSame(0, Expense::query()->count());
        } finally {
            Expense::flushEventListeners();
        }
    }

    public function test_update_and_cancel_only_registered_children_and_rollback_invalid_update(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 31));
        $service = app(RecurringExpenseService::class);
        $head = $service->createRecurringSeries($this->seriesData());
        $children = Expense::query()->where('parent_expense_id', $head->id)->orderBy('id')->get();
        $children[0]->update(['status' => ExpenseStatus::Distributed, 'distributed_at' => now()]);
        $service->updateFutureInstances($head, ['amount' => 200, 'notes' => 'Actualizado']);
        $this->assertSame('100.00', $children[0]->fresh()->amount);
        $this->assertSame('200.00', $children[1]->fresh()->amount);
        try {
            $service->updateFutureInstances($head, ['due_date' => '2024-03-31']);
            $this->fail('La fecha no es válida para todas las instancias.');
        } catch (ValidationException) {
            $this->assertSame('2024-03-31', $children[1]->fresh()->due_date->toDateString());
            $this->assertSame('2024-04-30', $children[2]->fresh()->due_date->toDateString());
        }
        $service->cancelFutureInstances($head);
        $this->assertSame(ExpenseStatus::Distributed, $children[0]->fresh()->status);
        $this->assertSame(ExpenseStatus::Cancelled, $children[1]->fresh()->status);
        $this->assertSame(ExpenseStatus::Registered, $head->fresh()->status);
    }

    public function test_future_head_is_rejected_but_future_child_is_allowed(): void
    {
        $head = Expense::factory()->create(['expense_date' => now()->toDateString(), 'due_date' => null]);
        $child = Expense::factory()->create(['parent_expense_id' => $head->id, 'expense_date' => now()->addMonth()->toDateString(), 'due_date' => null]);
        $this->assertNotNull($child->id);
        $this->expectException(ValidationException::class);
        Expense::factory()->create(['expense_date' => now()->addMonth()->toDateString(), 'due_date' => null]);
    }

    public function test_future_registered_child_can_generate_charges(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 31));
        $head = app(RecurringExpenseService::class)->createRecurringSeries($this->seriesData());
        $child = Expense::query()->where('parent_expense_id', $head->id)->firstOrFail();
        $owner = Propietario::factory()->create();
        $lote = Lote::factory()->create();
        $owner->lotes()->attach($lote, ['assigned_at' => now(), 'status' => 'active']);

        $charges = app(ChargeGenerationService::class)->generateChargesFromExpense($child);
        $this->assertCount(1, $charges);
        $this->assertSame(ExpenseStatus::Distributed, $child->fresh()->status);
    }

    public function test_rejects_non_editable_fields(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 31));
        $service = app(RecurringExpenseService::class);
        $head = $service->createRecurringSeries($this->seriesData());
        $this->expectException(DomainException::class);
        $service->updateFutureInstances($head, ['status' => ExpenseStatus::Paid]);
    }

    public function test_migration_is_reversible_without_removing_existing_period_constraint(): void
    {
        $migration = require database_path('migrations/2026_11_16_210641_add_recurrence_to_expenses_and_unique_accounting_period.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('expenses', 'parent_expense_id'));
        $this->assertTrue(Schema::hasIndex('accounting_periods', 'accounting_periods_year_month_unique', 'unique'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('expenses', 'parent_expense_id'));
    }
}
