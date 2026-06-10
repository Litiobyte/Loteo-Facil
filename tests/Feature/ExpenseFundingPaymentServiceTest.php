<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ExpenseFundingPaymentService;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Payment;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseFundingPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncome(float $amount = 500000): void
    {
        $owner = Propietario::factory()->create();

        Payment::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => $amount,
            'applied_amount' => 0,
            'unapplied_amount' => $amount,
            'status' => 'pending_application',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_registers_expense_funding_payment_and_updates_expense_totals(): void
    {
        $this->seedIncome();
        $expense = Expense::factory()->distributed()->create([
            'amount' => 120000,
            'funded_amount' => 0,
        ]);

        $payment = app(ExpenseFundingPaymentService::class)->register(
            expense: $expense,
            amount: 50000,
            paymentDate: now()->toDateString(),
            notes: 'Pago parcial',
        );

        $this->assertModelExists($payment);
        $this->assertSame($expense->id, $payment->expense_id);

        $expense->refresh();
        $this->assertSame(50000.0, (float) $expense->funded_amount);
        $this->assertSame(ExpenseStatus::Distributed, $expense->status);
    }

    public function test_can_edit_expense_funding_payment_and_recalculate_expense(): void
    {
        $this->seedIncome();
        $expense = Expense::factory()->distributed()->create([
            'amount' => 120000,
            'funded_amount' => 0,
        ]);

        $payment = app(ExpenseFundingPaymentService::class)->register(
            expense: $expense,
            amount: 40000,
            paymentDate: now()->toDateString(),
            notes: 'Inicial',
        );

        app(ExpenseFundingPaymentService::class)->update(
            payment: $payment,
            amount: 70000,
            paymentDate: now()->toDateString(),
            notes: 'Corregido',
        );

        $expense->refresh();
        $payment->refresh();

        $this->assertSame(70000.0, (float) $payment->amount);
        $this->assertSame('Corregido', $payment->notes);
        $this->assertSame(70000.0, (float) $expense->funded_amount);
    }

    public function test_can_void_expense_funding_payment_and_revert_expense_balance(): void
    {
        $this->seedIncome();
        $expense = Expense::factory()->distributed()->create([
            'amount' => 90000,
            'funded_amount' => 0,
        ]);

        $payment = app(ExpenseFundingPaymentService::class)->register(
            expense: $expense,
            amount: 90000,
            paymentDate: now()->toDateString(),
            notes: 'Pago total',
        );

        $this->assertSame(ExpenseStatus::Paid, $expense->fresh()->status);

        app(ExpenseFundingPaymentService::class)->void($payment, 'Digitación incorrecta');

        $payment->refresh();
        $expense->refresh();

        $this->assertTrue($payment->is_void);
        $this->assertSame('Digitación incorrecta', $payment->void_reason);
        $this->assertSame(0.0, (float) $expense->funded_amount);
        $this->assertSame(ExpenseStatus::Distributed, $expense->status);
    }

    public function test_register_requires_confirmation_if_cash_goes_negative(): void
    {
        $this->seedIncome(100000);
        $expense = Expense::factory()->distributed()->create([
            'amount' => 200000,
            'funded_amount' => 0,
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseFundingPaymentService::class)->register(
            expense: $expense,
            amount: 150000,
            paymentDate: now()->toDateString(),
            notes: null,
            allowNegativeCash: false,
        );
    }

    public function test_cannot_edit_voided_expense_funding_payment(): void
    {
        $this->seedIncome();
        $expense = Expense::factory()->distributed()->create([
            'amount' => 80000,
            'funded_amount' => 0,
        ]);

        $payment = ExpenseFundingPayment::factory()->voided()->create([
            'expense_id' => $expense->id,
            'amount' => 20000,
            'payment_date' => now()->toDateString(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un egreso anulado.');

        app(ExpenseFundingPaymentService::class)->update(
            payment: $payment,
            amount: 25000,
            paymentDate: now()->toDateString(),
            notes: 'Intento editar',
        );
    }
}
