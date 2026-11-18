<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Services\ChargeGenerationService;
use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Pages\MapaLoteo;
use App\Filament\Admin\Resources\CommunitySettingResource;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\ManageCommunitySettings;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ListPartnerCharges;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ViewPartnerCharge;
use App\Filament\Admin\Resources\ShareHoldingResource;
use App\Filament\Admin\Resources\ShareHoldingResource\Pages\ManageShareHoldings;
use App\Filament\Admin\Widgets\LotsSummaryStatWidget;
use App\Filament\Owner\Resources\Cobros\Pages\ViewCobro;
use App\Filament\Owner\Resources\ShareHoldingResource as OwnerShareResource;
use App\Filament\Owner\Resources\ShareHoldingResource\Pages\ListMyShares;
use App\Filament\Resources\Lotes\LoteResource;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShareFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'propietario'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function mode(string $mode): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => $mode, 'square_meters_per_share' => '800', 'share_precision' => 4, 'authorized_shares' => null,
        ], 'Cambio', $this->admin);
    }

    public function test_configuration_action_changes_mode_with_audit(): void
    {
        Livewire::test(ManageCommunitySettings::class)
            ->callTableAction('configure', CommunitySetting::current(), [
                'mode' => 'shares', 'square_meters_per_share' => '800', 'share_precision' => 2,
                'authorized_shares' => '5000', 'reason' => 'Comunidad inicial',
            ])->assertHasNoTableActionErrors();
        $this->assertSame('shares', CommunitySetting::current()->mode);
        $this->assertDatabaseCount('community_setting_revisions', 1);
    }

    public function test_admin_actions_acquire_adjust_transfer_and_show_history(): void
    {
        $this->mode('shares');
        $owner = Propietario::factory()->create();
        $recipient = Propietario::factory()->create();
        Livewire::test(ManageShareHoldings::class)
            ->callTableAction('acquire', data: [
                'propietario_id' => $owner->id, 'quantity' => '50', 'acquired_at' => today()->toDateString(),
                'operation_key' => 'ui-acquire', 'reason' => 'Asignación UI', 'amount' => '123456',
            ])->assertHasNoTableActionErrors();
        $holding = ShareHolding::query()->sole();
        Livewire::test(ManageShareHoldings::class)
            ->callTableAction('adjust', $holding, [
                'quantity' => '51', 'status' => 'active', 'operation_key' => 'ui-adjust', 'reason' => 'Ajuste UI',
            ])->assertHasNoTableActionErrors();
        Livewire::test(ManageShareHoldings::class)
            ->callTableAction('transfer', $holding, [
                'recipient_id' => $recipient->id, 'quantity' => '1', 'operation_key' => 'ui-transfer', 'reason' => 'Cesión UI',
            ])->assertHasNoTableActionErrors();
        $this->assertSame('50.0000', $holding->fresh()->quantity);
        $history = Livewire::test(ManageShareHoldings::class)->mountTableAction('history', $holding);
        $this->assertStringContainsString('Ajuste UI', $history->instance()->getMountedAction()->getModalContent()->render());
        $this->assertDatabaseCount('share_movements', 4);
    }

    public function test_admin_form_rejects_invalid_quantity(): void
    {
        $this->mode('shares');
        $owner = Propietario::factory()->create();
        Livewire::test(ManageShareHoldings::class)
            ->callTableAction('acquire', data: [
                'propietario_id' => $owner->id, 'quantity' => '-1', 'acquired_at' => today()->toDateString(),
                'operation_key' => 'invalid', 'reason' => 'Asignación', 'amount' => '100',
            ])->assertHasTableActionErrors(['quantity']);
        $this->assertDatabaseCount('share_holdings', 0);
    }

    public function test_navigation_and_distribution_choices_follow_mode_without_deleting_data(): void
    {
        $this->assertFalse(ShareHoldingResource::shouldRegisterNavigation());
        $this->assertTrue(LoteResource::shouldRegisterNavigation());
        $this->mode('shares');
        $this->assertTrue(ShareHoldingResource::shouldRegisterNavigation());
        $this->assertFalse(LoteResource::shouldRegisterNavigation());
        $this->assertFalse(\App\Filament\Owner\Resources\Lotes\LoteResource::shouldRegisterNavigation());
        $this->assertFalse(MapaLoteo::shouldRegisterNavigation());
        $this->assertFalse(LotsSummaryStatWidget::canView());
        $this->assertArrayNotHasKey(ExpenseDistributionType::ProportionalByHectares->value, ExpenseDistributionType::availableOptions());
        $this->mode('mixed');
        $this->assertTrue(LoteResource::shouldRegisterNavigation());
        $this->assertArrayHasKey(ExpenseDistributionType::ProportionalByShares->value, ExpenseDistributionType::availableOptions());
        $this->assertArrayHasKey(ExpenseDistributionType::ProportionalByHectares->value, ExpenseDistributionType::availableOptions());
    }

    public function test_owner_listing_and_history_are_scoped_and_read_only(): void
    {
        $this->mode('mixed');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $own = app(ShareHoldingService::class)->acquire($owner, '50', today()->toDateString(), 'own', 'Historial privado propio', $this->admin);
        $foreign = app(ShareHoldingService::class)->acquire($other, '25', today()->toDateString(), 'other', 'Historial privado ajeno', $this->admin);
        $owner->user->assignRole('propietario');
        $this->actingAs($owner->user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->assertFalse(ShareHoldingResource::canAccess());
        $this->assertFalse(CommunitySettingResource::canAccess());
        $this->assertFalse(OwnerShareResource::canCreate());
        $this->assertFalse(OwnerShareResource::canEdit($own));
        $this->assertFalse(OwnerShareResource::canDelete($own));
        $this->assertTrue(OwnerShareResource::canView($own));
        $this->assertFalse(OwnerShareResource::canView($foreign));
        $this->assertSame([$own->id], OwnerShareResource::getEloquentQuery()->pluck('id')->all());
        $history = Livewire::test(ListMyShares::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign])
            ->assertTableActionDoesNotExist('adjust')->assertTableActionDoesNotExist('transfer')
            ->mountTableAction('history', $own);
        $html = $history->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Historial privado propio', $html);
        $this->assertStringNotContainsString('Historial privado ajeno', $html);
        $forged = Livewire::test(ListMyShares::class)->mountTableAction('history', $foreign);
        $action = $forged->instance()->getMountedAction();
        $this->assertTrue($action === null || $action->getRecord() === null);
    }

    public function test_owner_without_profile_sees_no_shares(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);
        $this->assertFalse(OwnerShareResource::canAccess());
        $this->assertSame(0, OwnerShareResource::getEloquentQuery()->count());
    }

    public function test_share_expenses_and_charge_calculation_details_render_in_both_panels(): void
    {
        $this->mode('shares');
        $owner = Propietario::factory()->create();
        app(ShareHoldingService::class)->acquire($owner, '50', today()->toDateString(), 'render', 'Asignación', $this->admin);
        foreach ([ExpenseDistributionType::ProportionalByShares, ExpenseDistributionType::ProportionalByEquivalentArea] as $type) {
            $expense = Expense::factory()->create(['distribution_type' => $type]);
            $charge = app(ChargeGenerationService::class)->generateChargesFromExpense($expense)->sole();
            Livewire::test(ListExpenses::class)->assertCanSeeTableRecords([$expense]);
            Livewire::test(ListPartnerCharges::class)->assertCanSeeTableRecords([$charge]);
            Livewire::test(ViewPartnerCharge::class, ['record' => $charge->getRouteKey()])
                ->assertSee($type->getLabel())->assertSee('Base individual congelada')->assertSee('40.000');
            $owner->user->assignRole('propietario');
            $this->actingAs($owner->user);
            Filament::setCurrentPanel(Filament::getPanel('owner'));
            Livewire::test(ViewCobro::class, ['record' => $charge->getRouteKey()])
                ->assertSee($type->getLabel())->assertSee('Mis acciones al emitir')->assertSee('40.000');
            $this->actingAs($this->admin);
            Filament::setCurrentPanel(Filament::getPanel('admin'));
        }
    }

    public function test_business_rejection_in_action_is_reported_without_partial_transfer(): void
    {
        $this->mode('shares');
        $owner = Propietario::factory()->create();
        $recipient = Propietario::factory()->create();
        $holding = app(ShareHoldingService::class)->acquire($owner, '1', today()->toDateString(), 'one', 'Asignación', $this->admin);
        Livewire::test(ManageShareHoldings::class)->callTableAction('transfer', $holding, [
            'recipient_id' => $recipient->id, 'quantity' => '2', 'operation_key' => 'overdraw', 'reason' => 'Cesión',
        ])->assertNotified('Transferencia inválida o saldo insuficiente.');
        $this->assertSame('1.0000', $holding->fresh()->quantity);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertDatabaseCount('share_movements', 1);
    }
}
