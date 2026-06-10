<?php

namespace Tests\Feature;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Expenses\Services\ExpenseDistributionService;
use App\Models\Etapa;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ChargeGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChargeGenerationService(new ExpenseDistributionService);
    }

    public function test_equal_distribution_generates_correct_charges(): void
    {
        // Arrange: Crear 3 propietarios con lotes activos
        $category = ExpenseCategory::factory()->create(['name' => 'Mantenimiento']);
        $etapa = Etapa::factory()->create();

        $propietarios = Propietario::factory()->count(3)->create();
        foreach ($propietarios as $propietario) {
            $lote = Lote::factory()->create([
                'etapa_id' => $etapa->id,
                'metros_cuadrados' => 5000,
            ]);
            $propietario->lotes()->attach($lote->id, [
                'assigned_at' => now(),
                'status' => 'active',
            ]);
        }

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 540000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(3, $charges);
        $this->assertEquals(540000.00, $charges->sum('amount'));

        foreach ($charges as $charge) {
            $this->assertEquals(180000.00, $charge->amount);
            $this->assertEquals(0, $charge->paid_amount);
            $this->assertEquals(180000.00, $charge->remaining_amount);
            $this->assertEquals(ChargeStatus::Pending, $charge->status);
            $this->assertEquals(ExpenseDistributionType::EqualByPartner, $charge->calculation_type);
            $this->assertNotNull($charge->partner_hectares_at_moment);
            $this->assertNotNull($charge->total_hectares_at_moment);
            $this->assertNotNull($charge->percentage_applied);
        }

        // Verificar que el gasto fue marcado como distribuido
        $expense->refresh();
        $this->assertEquals(ExpenseStatus::Distributed, $expense->status);
        $this->assertNotNull($expense->distributed_at);
    }

    public function test_proportional_distribution_generates_correct_charges(): void
    {
        // Arrange: Propietarios con diferentes hectáreas
        $category = ExpenseCategory::factory()->create(['name' => 'Administración']);
        $etapa = Etapa::factory()->create();

        $propietario1 = Propietario::factory()->create(['nombre' => 'Propietario A']);
        $lote1 = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 10000, // 1 hectárea
        ]);
        $propietario1->lotes()->attach($lote1->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $propietario2 = Propietario::factory()->create(['nombre' => 'Propietario B']);
        $lote2 = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 30000, // 3 hectáreas
        ]);
        $propietario2->lotes()->attach($lote2->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 400000.00,
            'distribution_type' => ExpenseDistributionType::ProportionalByHectares,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(2, $charges);
        $this->assertEquals(400000.00, $charges->sum('amount'));

        // Propietario A: 1/4 = 25%
        $chargeA = $charges->firstWhere('propietario_id', $propietario1->id);
        $this->assertNotNull($chargeA);
        $this->assertEquals(100000.00, $chargeA->amount);
        $this->assertEquals(1.0, $chargeA->partner_hectares_at_moment);
        $this->assertEquals(4.0, $chargeA->total_hectares_at_moment);
        $this->assertEquals(25.0, $chargeA->percentage_applied);

        // Propietario B: 3/4 = 75%
        $chargeB = $charges->firstWhere('propietario_id', $propietario2->id);
        $this->assertNotNull($chargeB);
        $this->assertEquals(300000.00, $chargeB->amount);
        $this->assertEquals(3.0, $chargeB->partner_hectares_at_moment);
        $this->assertEquals(4.0, $chargeB->total_hectares_at_moment);
        $this->assertEquals(75.0, $chargeB->percentage_applied);
    }

    public function test_cannot_generate_charges_for_distributed_expense(): void
    {
        // Arrange
        $expense = Expense::factory()->create([
            'status' => ExpenseStatus::Distributed,
            'distributed_at' => now(),
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Solo se puede generar cobros para un gasto en estado registrado.');

        $this->service->generateChargesFromExpense($expense);
    }

    public function test_cannot_generate_charges_for_cancelled_expense(): void
    {
        // Arrange
        $expense = Expense::factory()->create([
            'status' => ExpenseStatus::Cancelled,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Solo se puede generar cobros para un gasto en estado registrado.');

        $this->service->generateChargesFromExpense($expense);
    }

    public function test_cannot_generate_charges_twice_for_same_expense(): void
    {
        // Arrange
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietario = Propietario::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 5000,
        ]);
        $propietario->lotes()->attach($lote->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
        ]);

        // Primera generación exitosa
        $this->service->generateChargesFromExpense($expense);

        // Crear un nuevo gasto en estado registrado pero con cobros manualmente creados
        // para simular idempotencia
        $expense2 = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
        ]);

        // Crear cobro manual para este gasto
        PartnerCharge::create([
            'propietario_id' => $propietario->id,
            'expense_id' => $expense2->id,
            'amount' => 100000.00,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending,
            'calculation_type' => ExpenseDistributionType::EqualByPartner,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Este gasto ya tiene cobros generados.');

        $this->service->generateChargesFromExpense($expense2);
    }

    public function test_cannot_distribute_when_no_active_owners(): void
    {
        // Arrange: Sin propietarios activos
        $category = ExpenseCategory::factory()->create();

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
        ]);

        // Act & Assert
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No hay propietarios activos con lotes activos para distribuir el gasto.');

        $this->service->generateChargesFromExpense($expense);
    }

    public function test_charge_description_is_generated_correctly(): void
    {
        // Arrange
        $category = ExpenseCategory::factory()->create(['name' => 'Impuestos']);
        $etapa = Etapa::factory()->create();

        $propietario = Propietario::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 5000,
        ]);
        $propietario->lotes()->attach($lote->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 50000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now()->parse('2026-05-15'),
            'due_date' => null,
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertStringContainsString('Impuestos', $charges->first()->description);
        $this->assertStringContainsString('2026-05', $charges->first()->description);
    }

    public function test_charges_inherit_due_date_from_expense(): void
    {
        // Arrange
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietario = Propietario::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 5000,
        ]);
        $propietario->lotes()->attach($lote->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $dueDate = now()->addDays(15);
        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 80000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => $dueDate,
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        foreach ($charges as $charge) {
            $this->assertEquals($dueDate->format('Y-m-d'), $charge->due_date->format('Y-m-d'));
        }
    }

    public function test_rounding_is_handled_correctly_for_uneven_amounts(): void
    {
        // Arrange: Monto que no se divide exactamente
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietarios = Propietario::factory()->count(3)->create();
        foreach ($propietarios as $propietario) {
            $lote = Lote::factory()->create([
                'etapa_id' => $etapa->id,
                'metros_cuadrados' => 5000,
            ]);
            $propietario->lotes()->attach($lote->id, [
                'assigned_at' => now(),
                'status' => 'active',
            ]);
        }

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100.00, // 100 / 3 = 33.33...
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $total = $charges->sum('amount');
        $this->assertEquals(100.00, $total);

        // Verificar que la diferencia por redondeo se compensó
        $amounts = $charges->pluck('amount')->toArray();
        sort($amounts);
        $this->assertEquals([33.33, 33.33, 33.34], $amounts);
    }
}
