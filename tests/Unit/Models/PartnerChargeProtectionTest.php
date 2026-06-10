<?php

namespace Tests\Unit\Models;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerChargeProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Expense $distributedExpense;

    private PartnerCharge $charge;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear propietario
        $propietario = Propietario::factory()->create();

        // Crear gasto en estado Distributed
        $this->distributedExpense = Expense::factory()->create([
            'status' => ExpenseStatus::Distributed,
        ]);

        // Crear cobro asociado al gasto distribuido
        $this->charge = PartnerCharge::factory()->create([
            'expense_id' => $this->distributedExpense->id,
            'propietario_id' => $propietario->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending,
            'calculation_type' => ExpenseDistributionType::EqualByPartner,
        ]);
    }

    public function test_allows_updating_paid_amount_on_charge_from_distributed_expense(): void
    {
        // Debería permitir actualizar paid_amount
        $this->charge->paid_amount = 50000;
        $this->charge->save();

        $this->assertEquals(50000, $this->charge->fresh()->paid_amount);
    }

    public function test_allows_updating_remaining_amount_on_charge_from_distributed_expense(): void
    {
        // Debería permitir actualizar remaining_amount
        // Nota: remaining_amount es un accessor calculado, pero lo incluimos en la whitelist
        // por si en el futuro se convierte en campo de DB
        $this->charge->paid_amount = 30000;
        $this->charge->save();

        $this->assertEquals(70000, $this->charge->fresh()->remaining_amount);
    }

    public function test_allows_updating_status_on_charge_from_distributed_expense(): void
    {
        // Debería permitir actualizar status
        $this->charge->status = ChargeStatus::Partial;
        $this->charge->save();

        $this->assertEquals(ChargeStatus::Partial, $this->charge->fresh()->status);
    }

    public function test_allows_updating_multiple_payment_fields_simultaneously(): void
    {
        // Debería permitir actualizar varios campos de pago a la vez
        $this->charge->paid_amount = 100000;
        $this->charge->status = ChargeStatus::Paid;
        $this->charge->save();

        $fresh = $this->charge->fresh();
        $this->assertEquals(100000, $fresh->paid_amount);
        $this->assertEquals(ChargeStatus::Paid, $fresh->status);
    }

    public function test_prevents_updating_amount_on_charge_from_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar el monto original
        $this->charge->amount = 200000;
        $this->charge->save();
    }

    public function test_prevents_updating_due_date_on_charge_from_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar la fecha de vencimiento
        $this->charge->due_date = now()->addDays(30);
        $this->charge->save();
    }

    public function test_prevents_updating_description_on_charge_from_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar la descripción
        $this->charge->description = 'Nueva descripción';
        $this->charge->save();
    }

    public function test_prevents_updating_if_mixing_allowed_and_blocked_fields(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar un campo permitido junto con uno bloqueado
        $this->charge->paid_amount = 50000; // Permitido
        $this->charge->amount = 200000; // Bloqueado
        $this->charge->save();
    }

    public function test_allows_all_changes_on_charge_from_draft_expense(): void
    {
        // Crear un gasto en Draft
        $draftExpense = Expense::factory()->create([
            'status' => ExpenseStatus::Registered,
        ]);

        $draftCharge = PartnerCharge::factory()->create([
            'expense_id' => $draftExpense->id,
            'amount' => 100000,
        ]);

        // Debería permitir cambiar cualquier campo cuando el gasto está en Draft
        $draftCharge->amount = 150000;
        $draftCharge->due_date = now()->addDays(60);
        $draftCharge->description = 'Descripción actualizada';
        $draftCharge->paid_amount = 10000;
        $draftCharge->status = ChargeStatus::Partial;
        $draftCharge->save();

        $fresh = $draftCharge->fresh();
        $this->assertEquals(150000, $fresh->amount);
        $this->assertNotNull($fresh->due_date);
        $this->assertEquals('Descripción actualizada', $fresh->description);
        $this->assertEquals(10000, $fresh->paid_amount);
        $this->assertEquals(ChargeStatus::Partial, $fresh->status);
    }

    public function test_prevents_updating_calculation_type_on_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar el tipo de cálculo
        $this->charge->calculation_type = ExpenseDistributionType::ProportionalByHectares;
        $this->charge->save();
    }

    public function test_prevents_updating_partner_hectares_on_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar las hectáreas del propietario
        $this->charge->partner_hectares_at_moment = 10.5;
        $this->charge->save();
    }

    public function test_prevents_updating_calculation_notes_on_distributed_expense(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No se puede editar un cobro de un gasto ya distribuido o cancelado.');

        // No debería permitir cambiar las notas de cálculo
        $this->charge->calculation_notes = 'Notas modificadas';
        $this->charge->save();
    }
}
