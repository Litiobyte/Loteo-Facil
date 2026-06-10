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
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChargeGenerationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private ChargeGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChargeGenerationService(new ExpenseDistributionService);
    }

    public function test_single_owner_receives_full_amount(): void
    {
        // Arrange: Un solo propietario activo
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietario = Propietario::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 10000,
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
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(1, $charges);
        $this->assertEquals(100000.00, $charges->first()->amount);
        $this->assertEquals(100.0, $charges->first()->percentage_applied);
    }

    public function test_seven_owners_complex_rounding(): void
    {
        // Arrange: 7 propietarios, monto que requiere redondeo complejo
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietarios = Propietario::factory()->count(7)->create();
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
            'amount' => 100000.00, // 100000 / 7 = 14285.71428...
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(7, $charges);

        // Validar suma exacta con tolerancia
        $total = $charges->sum('amount');
        $this->assertEqualsWithDelta(100000.00, $total, 0.01);

        // Validar que los montos sean razonables y estén cerca del promedio
        $average = 100000.00 / 7; // 14285.714285...
        foreach ($charges as $charge) {
            $this->assertEqualsWithDelta($average, $charge->amount, 1.0, 'Charge amount should be close to average');
        }
    }

    public function test_owner_with_multiple_lots_proportional_distribution(): void
    {
        // Arrange: 1 propietario con 3 lotes (2.5ha total), 4 propietarios con 1 lote (1ha cada uno)
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        // Propietario A con 3 lotes = 2.5 hectáreas
        $propietarioA = Propietario::factory()->create(['nombre' => 'Propietario A']);
        $lote1 = Lote::factory()->create(['etapa_id' => $etapa->id, 'metros_cuadrados' => 10000]); // 1ha
        $lote2 = Lote::factory()->create(['etapa_id' => $etapa->id, 'metros_cuadrados' => 10000]); // 1ha
        $lote3 = Lote::factory()->create(['etapa_id' => $etapa->id, 'metros_cuadrados' => 5000]);  // 0.5ha
        $propietarioA->lotes()->attach([$lote1->id, $lote2->id, $lote3->id], [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        // 4 propietarios con 1ha cada uno
        for ($i = 0; $i < 4; $i++) {
            $propietario = Propietario::factory()->create();
            $lote = Lote::factory()->create(['etapa_id' => $etapa->id, 'metros_cuadrados' => 10000]); // 1ha
            $propietario->lotes()->attach($lote->id, [
                'assigned_at' => now(),
                'status' => 'active',
            ]);
        }

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 650000.00,
            'distribution_type' => ExpenseDistributionType::ProportionalByHectares,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(5, $charges);

        // Total hectáreas: 2.5 + 4 = 6.5
        // Propietario A debe recibir: 2.5/6.5 * 650000 = 250000
        $chargeA = $charges->firstWhere('propietario_id', $propietarioA->id);
        $this->assertNotNull($chargeA);
        $this->assertEquals(2.5, $chargeA->partner_hectares_at_moment);
        $this->assertEquals(6.5, $chargeA->total_hectares_at_moment);
        $this->assertEquals(38.46, $chargeA->percentage_applied); // 2.5/6.5 * 100 = 38.46%

        // Validar que la suma total sea exacta
        $this->assertEquals(650000.00, $charges->sum('amount'));
    }

    public function test_snapshot_immutability_after_hectare_change(): void
    {
        // Arrange: Crear y distribuir gasto
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietario = Propietario::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 10000, // 1 hectárea
        ]);
        $propietario->lotes()->attach($lote->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100000.00,
            'distribution_type' => ExpenseDistributionType::ProportionalByHectares,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $charges = $this->service->generateChargesFromExpense($expense);
        $charge = $charges->first();

        // Guardar valores originales
        $originalHectares = $charge->partner_hectares_at_moment;
        $originalAmount = $charge->amount;

        // Act: Modificar las hectáreas del lote
        $lote->update(['metros_cuadrados' => 50000]); // Cambiar a 5 hectáreas

        // Assert: El cobro debe mantener el snapshot original
        $charge->refresh();
        $this->assertEquals($originalHectares, $charge->partner_hectares_at_moment);
        $this->assertEquals($originalAmount, $charge->amount);
        $this->assertEquals(1.0, $charge->partner_hectares_at_moment); // Sigue siendo 1ha
    }

    public function test_charge_cannot_be_edited_after_creation(): void
    {
        // Arrange: Crear cobro
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
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $charges = $this->service->generateChargesFromExpense($expense);
        $charge = $charges->first();

        // Act & Assert: Intentar modificar el monto debe fallar
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        $charge->update(['amount' => 50000.00]);
    }

    public function test_distributed_at_timestamp_is_set_correctly(): void
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
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Verificar que no está distribuido antes
        $this->assertNull($expense->distributed_at);

        $beforeDistribution = now();

        // Act
        $this->service->generateChargesFromExpense($expense);

        // Assert
        $expense->refresh();
        $this->assertNotNull($expense->distributed_at);
        $this->assertInstanceOf(Carbon::class, $expense->distributed_at);
        $this->assertTrue(
            $expense->distributed_at->between($beforeDistribution->subSecond(), now()->addSecond()),
            'distributed_at timestamp should be set during charge generation'
        );
    }

    public function test_invariant_sum_of_charges_equals_expense_amount(): void
    {
        // Arrange: Múltiples escenarios con diferentes configuraciones
        $scenarios = [
            ['owners' => 3, 'amount' => 540000.00],
            ['owners' => 5, 'amount' => 123456.78],
            ['owners' => 10, 'amount' => 999999.99],
            ['owners' => 2, 'amount' => 0.99],
        ];

        foreach ($scenarios as $scenario) {
            $category = ExpenseCategory::factory()->create();
            $etapa = Etapa::factory()->create();

            // Crear propietarios
            $propietarios = Propietario::factory()->count($scenario['owners'])->create();
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
                'amount' => $scenario['amount'],
                'distribution_type' => ExpenseDistributionType::EqualByPartner,
                'status' => ExpenseStatus::Registered,
                'expense_date' => now(),
                'due_date' => now()->addDays(30),
            ]);

            // Act
            $charges = $this->service->generateChargesFromExpense($expense);

            // Assert: Suma exacta con tolerancia de 1 centavo
            $total = $charges->sum('amount');
            $this->assertEqualsWithDelta(
                $scenario['amount'],
                $total,
                0.01,
                "Failed for {$scenario['owners']} owners with amount {$scenario['amount']}"
            );
        }
    }

    public function test_all_charges_have_correct_initial_state(): void
    {
        // Arrange
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

        $dueDate = now()->addDays(15);
        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 300000.00,
            'distribution_type' => ExpenseDistributionType::EqualByPartner,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => $dueDate,
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert: Validar todos los invariantes iniciales
        foreach ($charges as $charge) {
            // Estado inicial
            $this->assertEquals(ChargeStatus::Pending, $charge->status);
            $this->assertEquals(0, $charge->paid_amount);

            // Remaining amount correcto
            $expectedRemaining = $charge->amount - $charge->paid_amount;
            $this->assertEquals($expectedRemaining, $charge->remaining_amount);

            // Tipo de cálculo coincide
            $this->assertEquals($expense->distribution_type, $charge->calculation_type);

            // Due date coincide
            $this->assertEquals($dueDate->format('Y-m-d'), $charge->due_date->format('Y-m-d'));

            // Relaciones válidas
            $this->assertNotNull($charge->propietario_id);
            $this->assertNotNull($charge->expense_id);
            $this->assertEquals($expense->id, $charge->expense_id);
        }
    }

    public function test_proportional_with_very_small_hectares(): void
    {
        // Arrange: Hectáreas muy pequeñas (0.05ha)
        $category = ExpenseCategory::factory()->create();
        $etapa = Etapa::factory()->create();

        $propietario1 = Propietario::factory()->create();
        $lote1 = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 500, // 0.05 hectáreas
        ]);
        $propietario1->lotes()->attach($lote1->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $propietario2 = Propietario::factory()->create();
        $lote2 = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'metros_cuadrados' => 1500, // 0.15 hectáreas
        ]);
        $propietario2->lotes()->attach($lote2->id, [
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'amount' => 100000.00,
            'distribution_type' => ExpenseDistributionType::ProportionalByHectares,
            'status' => ExpenseStatus::Registered,
            'expense_date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Act
        $charges = $this->service->generateChargesFromExpense($expense);

        // Assert
        $this->assertCount(2, $charges);
        $this->assertEquals(100000.00, $charges->sum('amount'));

        // Propietario 1: 0.05/0.20 = 25%
        $charge1 = $charges->firstWhere('propietario_id', $propietario1->id);
        $this->assertEquals(0.05, $charge1->partner_hectares_at_moment);
        $this->assertEquals(25.0, $charge1->percentage_applied);

        // Propietario 2: 0.15/0.20 = 75%
        $charge2 = $charges->firstWhere('propietario_id', $propietario2->id);
        $this->assertEquals(0.15, $charge2->partner_hectares_at_moment);
        $this->assertEquals(75.0, $charge2->percentage_applied);
    }
}
