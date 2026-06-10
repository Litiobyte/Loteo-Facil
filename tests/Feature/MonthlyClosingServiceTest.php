<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentApplicationService;
use App\Models\AccountingPeriod;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MonthlyClosingService $service;

    private Propietario $propietario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MonthlyClosingService::class);
        $this->propietario = Propietario::factory()->create();
        $this->user = User::factory()->create();
    }

    /**
     * @throws DomainException
     */
    public function test_can_close_open_period_and_generate_folio_with_snapshot(): void
    {
        $targetDate = now()->startOfMonth()->addDays(5);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 120000,
            'paid_amount' => 0,
            'remaining_amount' => 120000,
            'status' => ChargeStatus::Pending,
            'due_date' => $targetDate->copy()->subDays(3)->toDateString(),
            'created_at' => $targetDate,
            'updated_at' => $targetDate,
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 60000,
            'applied_amount' => 0,
            'unapplied_amount' => 60000,
            'payment_date' => $targetDate->toDateString(),
            'status' => PaymentStatus::PendingApplication,
            'created_by' => $this->user->id,
        ]);

        $closed = $this->service->closePeriod((int) $targetDate->format('Y'), (int) $targetDate->format('m'), $this->user->id);

        $this->assertSame(AccountingPeriodStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertNotNull($closed->close_folio);
        $this->assertMatchesRegularExpression('/^CIERRE-\d{6}-\d{4}$/', (string) $closed->close_folio);
        $this->assertNotNull($closed->snapshot);
        $this->assertEquals(120000.0, (float) $closed->snapshot->total_charges);
        $this->assertEquals(60000.0, (float) $closed->snapshot->total_payments);
    }

    /**
     * @throws DomainException
     */
    public function test_payment_is_recognized_in_month_of_payment_not_charge_month(): void
    {
        $previousMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(2);
        $currentMonth = now()->startOfMonth()->addDays(2);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => ChargeStatus::Pending,
            'created_at' => $previousMonth,
            'updated_at' => $previousMonth,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100000,
            'applied_amount' => 0,
            'unapplied_amount' => 100000,
            'payment_date' => $currentMonth->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);
        app(PaymentApplicationService::class)->applyPaymentManually($payment, [
            ['charge_id' => $charge->id, 'amount' => 100000],
        ]);

        $closedCurrent = $this->service->closePeriod((int) $currentMonth->format('Y'), (int) $currentMonth->format('m'), $this->user->id);

        $this->assertNotNull($closedCurrent->snapshot);
        $this->assertEquals(0.0, (float) $closedCurrent->snapshot->total_charges);
        $this->assertEquals(100000.0, (float) $closedCurrent->snapshot->total_payments);
        $this->assertEquals(100000.0, (float) $closedCurrent->snapshot->total_allocations);
    }

    public function test_reopen_requires_reason_and_reopens_closed_period(): void
    {
        $period = AccountingPeriod::factory()->closed($this->user)->create([
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Debe indicar un motivo');
        $this->service->reopenPeriod($period, '  ', $this->user->id);
    }

    public function test_can_reopen_closed_period_with_reason(): void
    {
        $period = AccountingPeriod::factory()->closed($this->user)->create([
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        $reopened = $this->service->reopenPeriod($period, 'Ajuste por correccion documental', $this->user->id);

        $this->assertSame(AccountingPeriodStatus::Open, $reopened->status);
        $this->assertSame('Ajuste por correccion documental', $reopened->reopen_reason);
        $this->assertNotNull($reopened->reopened_at);
    }

    /**
     * @throws DomainException
     */
    public function test_cannot_close_period_twice(): void
    {
        $targetDate = now()->startOfMonth();

        $period = $this->service->closePeriod((int) $targetDate->format('Y'), (int) $targetDate->format('m'), $this->user->id);

        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya está cerrado');

        $this->service->closePeriod((int) $targetDate->format('Y'), (int) $targetDate->format('m'), $this->user->id);
    }

    /**
     * @throws DomainException
     */
    public function test_export_csv_contains_folio_and_rows(): void
    {
        $targetDate = now()->startOfMonth()->addDays(1);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 20000,
            'paid_amount' => 0,
            'remaining_amount' => 20000,
            'status' => ChargeStatus::Pending,
            'created_at' => $targetDate,
            'updated_at' => $targetDate,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 5000,
            'applied_amount' => 0,
            'unapplied_amount' => 5000,
            'payment_date' => $targetDate->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);
        $allocation = app(PaymentApplicationService::class)->applyPaymentAutomatically($payment)->first();
        $this->assertNotNull($allocation);
        $this->assertSame(1, PaymentAllocation::query()->count());

        $period = $this->service->closePeriod((int) $targetDate->format('Y'), (int) $targetDate->format('m'), $this->user->id);
        $response = $this->service->exportClosedPeriodCsv($period);

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringContainsString('Folio,Periodo,"Tipo movimiento"', $content);
        $this->assertStringContainsString((string) $period->close_folio, $content);
        $this->assertStringContainsString('Cobro emitido', $content);
        $this->assertStringContainsString('Pago recibido', $content);
    }

    /**
     * @throws DomainException
     */
    public function test_cannot_register_payment_in_closed_period(): void
    {
        $closedDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);

        $this->service->closePeriod((int) $closedDate->format('Y'), (int) $closedDate->format('m'), $this->user->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('período contable cerrado');

        Payment::query()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 15000,
            'applied_amount' => 0,
            'unapplied_amount' => 15000,
            'payment_date' => $closedDate->toDateString(),
            'payment_method' => 'transferencia',
            'status' => PaymentStatus::PendingApplication,
            'created_by' => $this->user->id,
        ]);
    }
}
