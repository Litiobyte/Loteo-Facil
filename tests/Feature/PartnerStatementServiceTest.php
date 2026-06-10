<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Payments\Services\PaymentApplicationService;
use App\Domain\Statements\Services\PartnerStatementService;
use App\Models\Etapa;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\Propietario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PartnerStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerStatementService $service;

    private Propietario $propietario;

    protected function setUp(): void
    {
        parent::setUp();
        $balanceService = new PartnerBalanceService;
        $this->service = new PartnerStatementService($balanceService);

        $this->propietario = Propietario::factory()->create();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 25000, // 2.5 hectares
            'codigo' => 'LOT-001',
        ]);
        $this->propietario->lotes()->attach($lote->id, ['assigned_at' => now(), 'status' => 'active']);
    }

    public function test_generate_statement_returns_complete_structure(): void
    {
        // Act
        $statement = $this->service->generateStatement($this->propietario);

        // Assert - structure
        $this->assertArrayHasKey('owner', $statement);
        $this->assertArrayHasKey('lots', $statement);
        $this->assertArrayHasKey('summary', $statement);
        $this->assertArrayHasKey('charges', $statement);
        $this->assertArrayHasKey('payments', $statement);
        $this->assertArrayHasKey('allocations', $statement);
        $this->assertArrayHasKey('timeline', $statement);

        // Assert - owner info
        $this->assertEquals($this->propietario->id, $statement['owner']['id']);
        $this->assertEquals($this->propietario->nombre_completo, $statement['owner']['name']);
        $this->assertEquals($this->propietario->rut, $statement['owner']['rut']);

        // Assert - lots info
        $this->assertCount(1, $statement['lots']);
        $this->assertEquals('LOT-001', $statement['lots'][0]['lot_code']);
        $this->assertEquals(2.5, $statement['lots'][0]['hectares']);

        // Assert - summary
        $this->assertEquals(2.5, $statement['summary']['total_hectares']);
    }

    public function test_get_charges_detail_returns_all_charges(): void
    {
        // Arrange
        $category = ExpenseCategory::factory()->create();
        $expense1 = Expense::factory()->create(['expense_category_id' => $category->id]);
        $expense2 = Expense::factory()->create(['expense_category_id' => $category->id]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'expense_id' => $expense1->id,
            'amount' => 100.00,
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'expense_id' => $expense2->id,
            'amount' => 200.00,
        ]);

        // Act
        $charges = $this->service->getChargesDetail($this->propietario);

        // Assert
        $this->assertCount(2, $charges);
        $this->assertInstanceOf(PartnerCharge::class, $charges->first());
    }

    public function test_get_charges_detail_filters_by_date_range(): void
    {
        // Arrange
        $oldCharge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'created_at' => Carbon::parse('2024-01-15'),
        ]);

        $recentCharge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'created_at' => Carbon::parse('2024-06-15'),
        ]);

        // Act
        $charges = $this->service->getChargesDetail(
            $this->propietario,
            Carbon::parse('2024-06-01'),
            Carbon::parse('2024-06-30')
        );

        // Assert
        $this->assertCount(1, $charges);
        $this->assertEquals($recentCharge->id, $charges->first()->id);
    }

    public function test_get_payments_detail_returns_all_payments(): void
    {
        // Arrange
        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 200.00,
        ]);

        // Act
        $payments = $this->service->getPaymentsDetail($this->propietario);

        // Assert
        $this->assertCount(2, $payments);
        $this->assertInstanceOf(Payment::class, $payments->first());
    }

    public function test_get_payments_detail_filters_by_date_range(): void
    {
        // Arrange
        $oldPayment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'payment_date' => Carbon::parse('2024-01-15'),
        ]);

        $recentPayment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'payment_date' => Carbon::parse('2024-06-15'),
        ]);

        // Act
        $payments = $this->service->getPaymentsDetail(
            $this->propietario,
            Carbon::parse('2024-06-01'),
            Carbon::parse('2024-06-30')
        );

        // Assert
        $this->assertCount(1, $payments);
        $this->assertEquals($recentPayment->id, $payments->first()->id);
    }

    public function test_get_allocations_detail_returns_all_allocations(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        $applicationService = new PaymentApplicationService;
        $applicationService->applyPaymentAutomatically($payment);

        // Act
        $allocations = $this->service->getAllocationsDetail($this->propietario);

        // Assert
        $this->assertCount(1, $allocations);
        $this->assertEquals($payment->id, $allocations->first()->payment_id);
        $this->assertEquals($charge->id, $allocations->first()->partner_charge_id);
    }

    public function test_get_movement_timeline_includes_all_types(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'status' => ChargeStatus::Pending,
            'created_at' => Carbon::parse('2024-06-01 10:00:00'),
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
            'payment_date' => Carbon::parse('2024-06-05'),
            'created_at' => Carbon::parse('2024-06-05 14:00:00'),
        ]);

        $applicationService = new PaymentApplicationService;
        $applicationService->applyPaymentAutomatically($payment);

        // Act
        $timeline = $this->service->getMovementTimeline($this->propietario);

        // Assert
        $this->assertGreaterThanOrEqual(3, $timeline->count()); // charge + payment + allocation
        $types = $timeline->pluck('type')->unique()->values()->all();
        $this->assertContains('charge', $types);
        $this->assertContains('payment', $types);
        $this->assertContains('allocation', $types);
    }

    public function test_timeline_is_sorted_by_date_descending(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'created_at' => Carbon::parse('2024-01-15'),
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'payment_date' => Carbon::parse('2024-06-15'),
            'created_at' => Carbon::parse('2024-06-15'),
        ]);

        // Act
        $timeline = $this->service->getMovementTimeline($this->propietario);

        // Assert - newest first
        $dates = $timeline->pluck('date')->all();
        $this->assertTrue($dates[0]->gte($dates[count($dates) - 1]));
    }

    public function test_timeline_balance_impact_is_correct(): void
    {
        // Arrange
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 150.00,
            'status' => ChargeStatus::Pending,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'amount' => 100.00,
        ]);

        // Act
        $timeline = $this->service->getMovementTimeline($this->propietario);

        // Assert
        $chargeMovement = $timeline->firstWhere('type', 'charge');
        $paymentMovement = $timeline->firstWhere('type', 'payment');

        $this->assertEquals(150.00, $chargeMovement['balance_impact']); // Positive = increases debt
        $this->assertEquals(-100.00, $paymentMovement['balance_impact']); // Negative = reduces debt
    }

    public function test_statement_with_date_range_filters_all_sections(): void
    {
        // Arrange
        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'created_at' => Carbon::parse('2024-01-15'),
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $this->propietario->id,
            'created_at' => Carbon::parse('2024-06-15'),
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'payment_date' => Carbon::parse('2024-01-20'),
        ]);

        Payment::factory()->create([
            'propietario_id' => $this->propietario->id,
            'payment_date' => Carbon::parse('2024-06-20'),
        ]);

        // Act
        $statement = $this->service->generateStatement(
            $this->propietario,
            Carbon::parse('2024-06-01'),
            Carbon::parse('2024-06-30')
        );

        // Assert - only June data
        $this->assertCount(1, $statement['charges']);
        $this->assertCount(1, $statement['payments']);
    }

    public function test_lots_info_includes_hectares_calculation(): void
    {
        // Arrange
        $etapa = Etapa::factory()->create();
        $lote2 = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 30000, // 3 hectares
            'codigo' => 'LOT-002',
        ]);
        $this->propietario->lotes()->attach($lote2->id, ['assigned_at' => now(), 'status' => 'active']);

        // Act
        $statement = $this->service->generateStatement($this->propietario);

        // Assert
        $this->assertCount(2, $statement['lots']);
        $totalHectares = collect($statement['lots'])->sum('hectares');
        $this->assertEquals(5.5, $totalHectares); // 2.5 + 3
    }
}
