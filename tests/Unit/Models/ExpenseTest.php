<?php

namespace Tests\Unit\Models;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_category_relation_works(): void
    {
        $expense = Expense::factory()->create();

        $this->assertTrue($expense->category->is($expense->category()->firstOrFail()));
    }

    public function test_belongs_to_creator_relation_works(): void
    {
        $creator = User::factory()->create();

        $expense = Expense::factory()->create([
            'created_by' => $creator->id,
        ]);

        $this->assertNotNull($expense->creator);
        $this->assertTrue($expense->creator->is($expense->creator()->firstOrFail()));
    }

    public function test_scopes_filter_by_status_and_category(): void
    {
        $categoryA = ExpenseCategory::factory()->create();
        $categoryB = ExpenseCategory::factory()->create();

        Expense::factory()->registered()->create(['expense_category_id' => $categoryA->id]);
        Expense::factory()->registered()->create(['expense_category_id' => $categoryB->id]);
        Expense::factory()->distributed()->create(['expense_category_id' => $categoryA->id]);
        Expense::factory()->cancelled()->create(['expense_category_id' => $categoryB->id]);

        $this->assertSame(2, Expense::query()->registered()->count());
        $this->assertSame(1, Expense::query()->distributed()->count());
        $this->assertSame(0, Expense::query()->paid()->count());
        $this->assertSame(1, Expense::query()->cancelled()->count());
        $this->assertSame(2, Expense::query()->byCategory($categoryA->id)->count());
    }

    public function test_status_is_cast_to_enum(): void
    {
        $expense = Expense::factory()->registered()->create();

        $this->assertSame(ExpenseStatus::Registered, $expense->status);
    }

    public function test_paid_scope_returns_paid_expenses(): void
    {
        Expense::factory()->paid()->create();
        Expense::factory()->distributed()->create();

        $this->assertSame(1, Expense::query()->paid()->count());
    }
}
