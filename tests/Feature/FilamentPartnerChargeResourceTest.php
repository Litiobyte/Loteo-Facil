<?php

namespace Tests\Feature;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentPartnerChargeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_charge_resource_access_is_restricted_to_admin_and_super_admin_roles(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $owner->assignRole('propietario');

        $this->actingAs($superAdmin);
        $this->assertTrue(PartnerChargeResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(PartnerChargeResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(PartnerChargeResource::canAccess());
    }

    public function test_partner_charge_resource_is_immutable_from_filament_permissions(): void
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $charge = $this->createPartnerCharge();

        $this->actingAs($admin);

        $this->assertFalse(PartnerChargeResource::canCreate());
        $this->assertFalse(PartnerChargeResource::canEdit($charge));
        $this->assertFalse(PartnerChargeResource::canDelete($charge));
    }

    public function test_admin_panel_registers_partner_charge_resource(): void
    {
        $resources = app(PanelRegistry::class)->get('admin')->getResources();

        $this->assertContains(PartnerChargeResource::class, $resources);
    }

    private function createPartnerCharge(): PartnerCharge
    {
        $expense = Expense::factory()->create();
        $propietario = Propietario::factory()->create();

        return PartnerCharge::query()->create([
            'propietario_id' => $propietario->id,
            'expense_id' => $expense->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => ChargeStatus::Pending,
            'due_date' => now()->addDays(10)->toDateString(),
            'description' => 'Cobro generado automáticamente.',
            'calculation_type' => ExpenseDistributionType::EqualByPartner,
            'partner_hectares_at_moment' => 2.5,
            'total_hectares_at_moment' => 10,
            'percentage_applied' => 25,
            'calculation_notes' => 'Distribución por partes iguales.',
        ]);
    }
}
