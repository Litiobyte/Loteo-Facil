<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ExpenseFundingService;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseFundingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedPositiveCash(float $amount = 500000): void
    {
        $owner = Propietario::factory()->create();

        Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => $amount,
            'applied_amount' => 0,
            'unapplied_amount' => $amount,
            'status' => 'pending_application',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_can_apply_partial_funding_to_distributed_expense(): void
    {
        $this->seedPositiveCash();

        $expense = Expense::factory()->distributed()->create([
            'amount' => 120000,
            'funded_amount' => 0,
        ]);

        $updated = app(ExpenseFundingService::class)->applyFunding($expense, 45000);

        $this->assertSame(ExpenseStatus::Distributed, $updated->status);
        $this->assertSame(45000.0, (float) $updated->funded_amount);
        $this->assertNull($updated->paid_at);
        $this->assertSame(1, ExpenseFundingPayment::query()->where('expense_id', $expense->id)->count());
    }

    public function test_marks_expense_as_paid_when_funding_reaches_full_amount(): void
    {
        $this->seedPositiveCash();

        $expense = Expense::factory()->distributed()->create([
            'amount' => 90000,
            'funded_amount' => 30000,
        ]);

        $updated = app(ExpenseFundingService::class)->applyFunding($expense, 60000);

        $this->assertSame(ExpenseStatus::Paid, $updated->status);
        $this->assertSame(90000.0, (float) $updated->funded_amount);
        $this->assertNotNull($updated->paid_at);
        $this->assertSame(2, ExpenseFundingPayment::query()->where('expense_id', $expense->id)->count());
    }

    public function test_cannot_apply_funding_to_non_distributed_expense(): void
    {
        $this->seedPositiveCash();

        $expense = Expense::factory()->registered()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Solo se puede registrar pago de caja para gastos distribuidos.');

        app(ExpenseFundingService::class)->applyFunding($expense, 10000);
    }

    public function test_cannot_overpay_expense_funding(): void
    {
        $this->seedPositiveCash();

        $expense = Expense::factory()->distributed()->create([
            'amount' => 50000,
            'funded_amount' => 20000,
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseFundingService::class)->applyFunding($expense, 40000);
    }

    public function test_cannot_apply_funding_when_it_makes_cash_negative_without_confirmation(): void
    {
        $this->seedPositiveCash(100000);

        $expense = Expense::factory()->distributed()->create([
            'amount' => 200000,
            'funded_amount' => 0,
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseFundingService::class)->applyFunding($expense, 150000);
    }

    public function test_can_apply_funding_when_it_makes_cash_negative_with_confirmation(): void
    {
        $this->seedPositiveCash(100000);

        $expense = Expense::factory()->distributed()->create([
            'amount' => 200000,
            'funded_amount' => 0,
        ]);

        $updated = app(ExpenseFundingService::class)->applyFunding($expense, 150000, true);

        $this->assertSame(150000.0, (float) $updated->funded_amount);
    }
}
