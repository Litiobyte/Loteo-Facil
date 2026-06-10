<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Payments\Services\PaymentApplicationService;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\Propietario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerBalanceService $service;

    private Propietario $propietario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PartnerBalanceService;

        $this->propietario = Propietario::factory()->create();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 50000, // 5 hectares
        ]);
        $this->propietario->lotes()->attach($lote->id, ['assigned_at' => now(), 'status' => 'active']);
    }

    public function test_get_pending_balance_sums_unpaid_charges(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'remaining_amount' => 100.00,
            'status' => ChargeStatus::Pending,
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'paid_amount' => 50.00,
            'remaining_amount' => 100.00,
            'status' => ChargeStatus::Partial,
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 80.00,
            'paid_amount' => 80.00,
            'remaining_amount' => 0.00,
            'status' => ChargeStatus::Paid,
        ]);

        // Act
        $pending = $this->service->getPendingBalance($this->propietario);

        // Assert - only unpaid charges
        $this->assertEquals(200.00, $pending); // 100 + 100
    }

    public function test_get_credit_balance_sums_unapplied_payments(): void
    {
        // Arrange
        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'applied_amount' => 150.00,
            'unapplied_amount' => 50.00,
            'status' => 'partially_applied',
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'applied_amount' => 0,
            'unapplied_amount' => 100.00,
            'status' => 'pending_application',
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 80.00,
            'applied_amount' => 80.00,
            'unapplied_amount' => 0.00,
            'status' => 'fully_applied',
        ]);

        // Act
        $credit = $this->service->getCreditBalance($this->propietario);

        // Assert - only unapplied amounts
        $this->assertEquals(150.00, $credit); // 50 + 100
    }

    public function test_get_total_charges_sums_all_charges(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
        ]);

        // Act
        $total = $this->service->getTotalCharges($this->propietario);

        // Assert
        $this->assertEquals(300.00, $total);
    }

    public function test_get_total_payments_excludes_cancelled(): void
    {
        // Arrange
        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'status' => 'pending_application',
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'status' => 'fully_applied',
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 50.00,
            'status' => 'cancelled',
        ]);

        // Act
        $total = $this->service->getTotalPayments($this->propietario);

        // Assert - cancelled not included
        $this->assertEquals(300.00, $total);
    }

    public function test_get_total_applied_sums_applied_amounts(): void
    {
        // Arrange
        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'applied_amount' => 80.00,
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'applied_amount' => 200.00,
        ]);

        // Act
        $total = $this->service->getTotalApplied($this->propietario);

        // Assert
        $this->assertEquals(280.00, $total);
    }

    public function test_get_balance_summary_returns_complete_info(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'remaining_amount' => 100.00,
            'status' => ChargeStatus::Pending,
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'paid_amount' => 150.00,
            'remaining_amount' => 50.00,
            'status' => ChargeStatus::Partial,
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'applied_amount' => 150.00,
            'unapplied_amount' => 50.00,
        ]);

        // Act
        $summary = $this->service->getBalanceSummary($this->propietario);

        // Assert
        $this->assertEquals(300.00, $summary['total_charges']);
        $this->assertEquals(200.00, $summary['total_payments']);
        $this->assertEquals(150.00, $summary['total_applied']);
        $this->assertEquals(150.00, $summary['pending_balance']); // 100 + 50
        $this->assertEquals(50.00, $summary['credit_balance']);
        $this->assertEquals(-100.00, $summary['net_balance']); // 50 - 150
        $this->assertEquals(5.0, $summary['total_hectares']); // 50000 m² = 5 ha
    }

    public function test_validate_balance_consistency_detects_charge_inconsistency(): void
    {
        // Arrange - manually create inconsistent charge
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 60.00,
            'remaining_amount' => 50.00, // Should be 40, this is inconsistent
            'status' => ChargeStatus::Partial,
        ]);

        // Bypass validation by using updateQuietly
        $charge->updateQuietly(['remaining_amount' => 50.00]);

        // Act
        $result = $this->service->validateBalanceConsistency($this->propietario);

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Inconsistencia en cobros', $result['errors'][0]);
    }

    public function test_validate_balance_consistency_detects_payment_inconsistency(): void
    {
        // Arrange - manually create inconsistent payment
        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'applied_amount' => 60.00,
            'unapplied_amount' => 50.00, // Should be 40, this is inconsistent
        ]);

        $payment->updateQuietly(['unapplied_amount' => 50.00]);

        // Act
        $result = $this->service->validateBalanceConsistency($this->propietario);

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Inconsistencia en pagos', $result['errors'][0]);
    }

    public function test_validate_balance_consistency_detects_charge_payment_mismatch(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 80.00,
            'remaining_amount' => 20.00,
        ]);

        // Payment with different applied amount (mismatch)
        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'applied_amount' => 100.00, // Doesn't match charge paid_amount
            'unapplied_amount' => 50.00,
        ]);

        // Act
        $result = $this->service->validateBalanceConsistency($this->propietario);

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('cobros pagados', $result['errors'][0]);
    }

    public function test_validate_balance_consistency_passes_for_valid_data(): void
    {
        // Arrange - create valid, consistent data using services
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'remaining_amount' => 100.00,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'applied_amount' => 0,
            'unapplied_amount' => 100.00,
        ]);

        // Apply payment properly
        $applicationService = new PaymentApplicationService;
        $applicationService->applyPaymentAutomatically($payment);

        // Act
        $result = $this->service->validateBalanceConsistency($this->propietario);

        // Assert
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_net_balance_negative_when_owes_money(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 500.00,
            'paid_amount' => 0,
            'remaining_amount' => 500.00,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'applied_amount' => 0,
            'unapplied_amount' => 200.00,
        ]);

        // Apply payment to charge
        $applicationService = new PaymentApplicationService;
        $applicationService->applyPaymentAutomatically($payment);

        // Act
        $summary = $this->service->getBalanceSummary($this->propietario);

        // Assert - owes 300 (500 - 200)
        $this->assertEquals(300.00, $summary['pending_balance']);
        $this->assertEquals(0.00, $summary['credit_balance']);
        $this->assertEquals(-300.00, $summary['net_balance']); // negative = owes money
    }

    public function test_net_balance_positive_when_has_credit(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 100.00,
            'remaining_amount' => 0.00,
            'status' => ChargeStatus::Paid,
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'applied_amount' => 100.00,
            'unapplied_amount' => 100.00,
        ]);

        // Act
        $summary = $this->service->getBalanceSummary($this->propietario);

        // Assert - has 100 in credit
        $this->assertEquals(0.00, $summary['pending_balance']);
        $this->assertEquals(100.00, $summary['credit_balance']);
        $this->assertEquals(100.00, $summary['net_balance']); // positive = has credit
    }
}
