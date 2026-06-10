<?php

namespace Tests\Feature;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentApplicationService;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentApplicationService $service;

    private Propietario $propietario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentApplicationService;

        // Create propietario with lote for test consistency
        $this->propietario = Propietario::factory()->create();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create(['etapa_id' => $etapa->id]);
        $this->propietario->lotes()->attach($lote->id, ['assigned_at' => now(), 'status' => 'active']);
    }

    public function test_exact_payment_covers_one_charge_completely(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'applied_amount' => 0,
            'unapplied_amount' => 100.00,
            'status' => PaymentStatus::PendingApplication,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert
        $this->assertCount(1, $allocations);
        $this->assertEquals(100.00, $allocations->first()->amount);

        $charge->refresh();
        $this->assertEquals(100.00, $charge->paid_amount);
        $this->assertEquals(0.00, $charge->remaining_amount);
        $this->assertEquals(ChargeStatus::Paid, $charge->status);

        $payment->refresh();
        $this->assertEquals(100.00, $payment->applied_amount);
        $this->assertEquals(0.00, $payment->unapplied_amount);
        $this->assertEquals(PaymentStatus::FullyApplied, $payment->status);
    }

    public function test_partial_payment_leaves_charge_in_partial_status(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 80.00,
            'applied_amount' => 0,
            'unapplied_amount' => 80.00,
            'status' => PaymentStatus::PendingApplication,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert
        $this->assertCount(1, $allocations);
        $this->assertEquals(80.00, $allocations->first()->amount);

        $charge->refresh();
        $this->assertEquals(80.00, $charge->paid_amount);
        $this->assertEquals(120.00, $charge->remaining_amount);
        $this->assertEquals(ChargeStatus::Partial, $charge->status);

        $payment->refresh();
        $this->assertEquals(80.00, $payment->applied_amount);
        $this->assertEquals(0.00, $payment->unapplied_amount);
        $this->assertEquals(PaymentStatus::FullyApplied, $payment->status);
    }

    public function test_overpayment_generates_credit_balance(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'applied_amount' => 0,
            'unapplied_amount' => 150.00,
            'status' => PaymentStatus::PendingApplication,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert
        $this->assertCount(1, $allocations);
        $this->assertEquals(100.00, $allocations->first()->amount);

        $charge->refresh();
        $this->assertEquals(100.00, $charge->paid_amount);
        $this->assertEquals(0.00, $charge->remaining_amount);
        $this->assertEquals(ChargeStatus::Paid, $charge->status);

        $payment->refresh();
        $this->assertEquals(100.00, $payment->applied_amount);
        $this->assertEquals(50.00, $payment->unapplied_amount); // Credit balance
        $this->assertEquals(PaymentStatus::PartiallyApplied, $payment->status);
    }

    public function test_payment_covers_multiple_charges_fifo(): void
    {
        // Arrange
        $charge1 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 50.00,
            'paid_amount' => 0,
            'due_date' => now()->subDays(10),
            'status' => ChargeStatus::Pending,
        ]);

        $charge2 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 60.00,
            'paid_amount' => 0,
            'due_date' => now()->subDays(5),
            'status' => ChargeStatus::Pending,
        ]);

        $charge3 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 70.00,
            'paid_amount' => 0,
            'due_date' => now()->subDays(2),
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'applied_amount' => 0,
            'unapplied_amount' => 150.00,
            'status' => PaymentStatus::PendingApplication,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert - FIFO: oldest first
        $this->assertCount(3, $allocations);
        $this->assertEquals($charge1->id, $allocations[0]->partner_charge_id);
        $this->assertEquals(50.00, $allocations[0]->amount);
        $this->assertEquals($charge2->id, $allocations[1]->partner_charge_id);
        $this->assertEquals(60.00, $allocations[1]->amount);
        $this->assertEquals($charge3->id, $allocations[2]->partner_charge_id);
        $this->assertEquals(40.00, $allocations[2]->amount); // Partial

        $charge1->refresh();
        $this->assertEquals(ChargeStatus::Paid, $charge1->status);

        $charge2->refresh();
        $this->assertEquals(ChargeStatus::Paid, $charge2->status);

        $charge3->refresh();
        $this->assertEquals(ChargeStatus::Partial, $charge3->status);
        $this->assertEquals(30.00, $charge3->remaining_amount);

        $payment->refresh();
        $this->assertEquals(150.00, $payment->applied_amount);
        $this->assertEquals(0.00, $payment->unapplied_amount);
        $this->assertEquals(PaymentStatus::FullyApplied, $payment->status);
    }

    public function test_payment_exhausted_before_all_charges_covered(): void
    {
        // Arrange
        $charge1 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'due_date' => now()->subDays(10),
            'status' => ChargeStatus::Pending,
        ]);

        $charge2 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
            'due_date' => now()->subDays(5),
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 80.00,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert - only first charge gets paid (partially)
        $this->assertCount(1, $allocations);
        $this->assertEquals($charge1->id, $allocations[0]->partner_charge_id);
        $this->assertEquals(80.00, $allocations[0]->amount);

        $charge1->refresh();
        $this->assertEquals(ChargeStatus::Partial, $charge1->status);
        $this->assertEquals(20.00, $charge1->remaining_amount);

        $charge2->refresh();
        $this->assertEquals(ChargeStatus::Pending, $charge2->status);
        $this->assertEquals(0.00, $charge2->paid_amount);
    }

    public function test_cannot_apply_payment_to_charge_from_different_propietario(): void
    {
        // Arrange
        $otherPropietario = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $otherPropietario->id,
            'amount' => 100.00,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no pertenece al propietario del pago');

        $this->service->applyPaymentManually($payment, [
            ['charge_id' => $charge->id, 'amount' => 100.00],
        ]);
    }

    public function test_cannot_apply_more_than_payment_unapplied_amount(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede el saldo disponible del pago');

        $this->service->applyPaymentManually($payment, [
            ['charge_id' => $charge->id, 'amount' => 150.00],
        ]);
    }

    public function test_cannot_apply_more_than_charge_remaining_amount(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'paid_amount' => 0,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede su saldo pendiente');

        $this->service->applyPaymentManually($payment, [
            ['charge_id' => $charge->id, 'amount' => 150.00],
        ]);
    }

    public function test_manual_application_works_correctly(): void
    {
        // Arrange
        $charge1 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'due_date' => now()->subDays(10),
        ]);

        $charge2 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'due_date' => now()->subDays(5),
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
        ]);

        // Act - manually apply to charge2 first (not FIFO)
        $allocations = $this->service->applyPaymentManually($payment, [
            ['charge_id' => $charge2->id, 'amount' => 150.00],
            ['charge_id' => $charge1->id, 'amount' => 50.00],
        ]);

        // Assert
        $this->assertCount(2, $allocations);

        $charge1->refresh();
        $this->assertEquals(50.00, $charge1->paid_amount);
        $this->assertEquals(ChargeStatus::Partial, $charge1->status);

        $charge2->refresh();
        $this->assertEquals(150.00, $charge2->paid_amount);
        $this->assertEquals(ChargeStatus::Paid, $charge2->status);

        $payment->refresh();
        $this->assertEquals(200.00, $payment->applied_amount);
        $this->assertEquals(PaymentStatus::FullyApplied, $payment->status);
    }

    public function test_reversal_restores_correct_states(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        $allocations = $this->service->applyPaymentAutomatically($payment);
        $allocation = $allocations->first();

        // Verify applied
        $charge->refresh();
        $payment->refresh();
        $this->assertEquals(ChargeStatus::Paid, $charge->status);
        $this->assertEquals(PaymentStatus::FullyApplied, $payment->status);

        // Act - reverse
        $this->service->reverseAllocation($allocation);

        // Assert - states restored
        $charge->refresh();
        $this->assertEquals(0.00, $charge->paid_amount);
        $this->assertEquals(100.00, $charge->remaining_amount);
        $this->assertEquals(ChargeStatus::Pending, $charge->status);

        $payment->refresh();
        $this->assertEquals(0.00, $payment->applied_amount);
        $this->assertEquals(100.00, $payment->unapplied_amount);
        $this->assertEquals(PaymentStatus::PendingApplication, $payment->status);

        $this->assertDatabaseMissing('payment_allocations', ['id' => $allocation->id]);
    }

    public function test_cannot_apply_cancelled_payment(): void
    {
        // Arrange
        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'status' => PaymentStatus::Cancelled,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede aplicar un pago cancelado');

        $this->service->applyPaymentAutomatically($payment);
    }

    public function test_cannot_apply_fully_applied_payment(): void
    {
        // Arrange
        $payment = Payment::factory()->fullyApplied()->create([
            'propietario_id' => $this->propietario->id,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('completamente aplicado');

        $this->service->applyPaymentAutomatically($payment);
    }

    public function test_no_unpaid_charges_throws_exception(): void
    {
        // Arrange
        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        // No charges created

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No hay cobros pendientes');

        $this->service->applyPaymentAutomatically($payment);
    }

    public function test_preview_automatic_allocation_does_not_save(): void
    {
        // Arrange
        $charge1 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'due_date' => now()->subDays(10),
        ]);

        $charge2 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 50.00,
            'due_date' => now()->subDays(5),
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 120.00,
        ]);

        // Act
        $preview = $this->service->previewAutomaticAllocation($payment);

        // Assert
        $this->assertCount(2, $preview['allocations']);
        $this->assertEquals(100.00, $preview['allocations'][0]['allocation_amount']);
        $this->assertEquals(20.00, $preview['allocations'][1]['allocation_amount']);
        $this->assertEquals(120.00, $preview['total_allocated']);
        $this->assertEquals(0.00, $preview['remaining_credit']);

        // Verify nothing was saved
        $this->assertEquals(0, PaymentAllocation::count());
        $charge1->refresh();
        $charge2->refresh();
        $this->assertEquals(0.00, $charge1->paid_amount);
        $this->assertEquals(0.00, $charge2->paid_amount);
    }

    public function test_all_sums_always_balance_correctly(): void
    {
        // Arrange - complex scenario
        $charges = PartnerCharge::factory()->count(5)->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 33.33, // Rounding challenge
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        // Act
        $this->service->applyPaymentAutomatically($payment);

        // Assert - balance check
        $payment->refresh();
        $totalAllocated = PaymentAllocation::where('payment_id', $payment->id)->sum('amount');

        $this->assertEquals($payment->applied_amount, $totalAllocated);
        $this->assertEquals(
            $payment->amount,
            round((float) $payment->applied_amount + (float) $payment->unapplied_amount, 2)
        );

        foreach ($charges as $charge) {
            $charge->refresh();
            $this->assertEquals(
                $charge->amount,
                round((float) $charge->paid_amount + (float) $charge->remaining_amount, 2)
            );
        }
    }

    public function test_charges_with_null_due_date_are_ordered_last(): void
    {
        // Arrange
        $charge1 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 50.00,
            'due_date' => null, // No due date
        ]);

        $charge2 = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 60.00,
            'due_date' => now()->subDays(5),
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 60.00,
        ]);

        // Act
        $allocations = $this->service->applyPaymentAutomatically($payment);

        // Assert - charge2 (with due_date) should be paid first
        $this->assertCount(1, $allocations);
        $this->assertEquals($charge2->id, $allocations->first()->partner_charge_id);
    }
}
