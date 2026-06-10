<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_requires_positive_amount(): void
    {
        $category = ExpenseCategory::factory()->create();

        $this->expectException(ValidationException::class);

        Expense::query()->create([
            'expense_category_id' => $category->id,
            'title' => 'Gasto inválido',
            'amount' => 0,
            'expense_date' => now()->toDateString(),
            'distribution_type' => ExpenseDistributionType::EqualByPartner->value,
            'status' => ExpenseStatus::Registered->value,
        ]);
    }

    public function test_expense_requires_distribution_type(): void
    {
        $category = ExpenseCategory::factory()->create();

        $this->expectException(ValidationException::class);

        Expense::query()->create([
            'expense_category_id' => $category->id,
            'title' => 'Gasto sin tipo de distribución',
            'amount' => 100000,
            'expense_date' => now()->toDateString(),
            'status' => ExpenseStatus::Registered->value,
        ]);
    }

    public function test_due_date_must_be_greater_than_or_equal_to_expense_date(): void
    {
        $category = ExpenseCategory::factory()->create();

        $this->expectException(ValidationException::class);

        Expense::query()->create([
            'expense_category_id' => $category->id,
            'title' => 'Gasto con fechas inválidas',
            'amount' => 450000,
            'expense_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'distribution_type' => ExpenseDistributionType::Manual->value,
            'status' => ExpenseStatus::Registered->value,
        ]);
    }

    public function test_cannot_modify_distributed_expense_without_special_logic(): void
    {
        $expense = Expense::factory()->distributed()->create();

        $this->expectException(DomainException::class);

        $expense->update([
            'title' => 'Título modificado inválidamente',
        ]);
    }

    public function test_cancelled_expense_cannot_return_to_registered(): void
    {
        $expense = Expense::factory()->cancelled()->create();

        $this->expectException(DomainException::class);

        $expense->update([
            'status' => ExpenseStatus::Registered,
        ]);
    }

    public function test_cannot_delete_category_with_linked_expenses(): void
    {
        $expense = Expense::factory()->create();

        $this->expectException(QueryException::class);

        $expense->category->delete();
    }

    public function test_cannot_set_paid_status_without_full_funding(): void
    {
        $expense = Expense::factory()->distributed()->create([
            'amount' => 100000,
            'funded_amount' => 50000,
        ]);

        $this->expectException(DomainException::class);

        $expense->update([
            'status' => ExpenseStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
