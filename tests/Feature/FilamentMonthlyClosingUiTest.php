<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\CreateAccountingPeriod;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\EditAccountingPeriod;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\AccountingPeriods\Tables\AccountingPeriodsTable;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Filament\Owner\Resources\Cobros\Pages\ListMisCobros;
use App\Filament\Owner\Resources\Cobros\Pages\ViewCobro;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentMonthlyClosingUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_edit_periods_show_guidance_and_ordered_native_actions(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(CreateAccountingPeriod::class)
            ->assertOk()
            ->assertSee('Información del cierre')
            ->assertSee('¿Qué es un cierre contable?')
            ->assertSee('Validaciones')
            ->assertSee('Las fechas se autocompletan al elegir año o mes')
            ->assertSee('Define el período, estado y folio')
            ->assertSeeHtml('fi-size-lg')
            ->assertSeeInOrder(['Cancelar', 'Crear y crear otro', 'Crear cierre'])
            ->assertSeeHtml('href="'.AccountingPeriodResource::getUrl('index').'"')
            ->assertFormFieldIsDisabled('status')
            ->assertFormFieldIsHidden('close_folio');

        $this->assertStringContainsString('<svg', $page->instance()->getHeading()->render());

        $period = AccountingPeriod::query()->create(['year' => 2026, 'month' => 10, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'open']);
        Livewire::test(EditAccountingPeriod::class, ['record' => $period->id])
            ->assertOk()
            ->assertSee('Información del cierre')
            ->assertSeeHtml('fi-size-lg')
            ->assertSeeInOrder(['Cancelar', 'Guardar cambios'])
            ->assertDontSee('Crear y crear otro')
            ->assertFormFieldIsDisabled('status')
            ->assertFormFieldIsDisabled('close_folio');
    }

    public function test_period_form_preserves_validation_and_existing_date_normalization(): void
    {
        $this->travelTo(now()->setDate(2029, 6, 15));

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAccountingPeriod::class)
            ->fillForm(['year' => 2019, 'month' => 13, 'period_start' => null, 'period_end' => null])
            ->call('create')
            ->assertHasFormErrors(['year' => 'min', 'month' => 'between', 'period_start' => 'required', 'period_end' => 'required']);

        $page = Livewire::test(CreateAccountingPeriod::class)
            ->fillForm(['year' => 2028, 'month' => 2, 'period_start' => '2028-01-10', 'period_end' => '2028-03-05'])
            ->assertSet('data.period_start', '2028-01-10')
            ->assertSet('data.period_end', '2028-03-05')
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
        $this->assertDatabaseHas('accounting_periods', [
            'year' => 2028, 'month' => 2, 'period_start' => '2028-02-01 00:00:00', 'period_end' => '2028-02-29 00:00:00',
            'status' => 'open', 'close_folio' => null,
        ]);
        $this->assertSame(2029, (int) $page->get('data.year'));

        $period = AccountingPeriod::query()->where('year', 2028)->where('month', 2)->firstOrFail();
        Livewire::test(EditAccountingPeriod::class, ['record' => $period->id])
            ->fillForm(['year' => 2028, 'month' => 3, 'period_start' => '2028-01-10', 'period_end' => '2028-03-05'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('2028-03-01', $period->fresh()->period_start->toDateString());
        $this->assertSame('2028-03-31', $period->fresh()->period_end->toDateString());
        $period->update(['status' => 'closed']);
        Livewire::test(EditAccountingPeriod::class, ['record' => $period->id])->assertForbidden();
    }

    public function test_non_admin_cannot_create_accounting_periods(): void
    {
        Role::findOrCreate('propietario', 'web');
        $owner = User::factory()->create();
        $owner->assignRole('propietario');
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAccountingPeriod::class)->assertForbidden();
    }

    public function test_create_period_defaults_and_select_options_are_limited_to_past_and_current_years(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 15));
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAccountingPeriod::class)
            ->assertFormSet(['year' => 2026, 'month' => 6])
            ->assertFormFieldExists('year', function (Select $field): bool {
                $this->assertSame([2026, 2025, 2024, 2023, 2022, 2021], array_keys($field->getOptions()));

                return true;
            })
            ->assertFormFieldExists('month', function (Select $field): bool {
                $this->assertCount(12, $field->getOptions());
                $this->assertSame('Enero', $field->getOptions()[1]);
                $this->assertSame('Diciembre', $field->getOptions()[12]);

                return true;
            })
            ->assertFormFieldIsHidden('close_folio');
    }

    public function test_selecting_year_or_month_updates_period_dates_while_staying_editable(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 15));
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAccountingPeriod::class)
            ->fillForm(['year' => 2025, 'month' => 2])
            ->assertSet('data.period_start', '2025-02-01')
            ->assertSet('data.period_end', '2025-02-28')
            ->fillForm(['month' => 11])
            ->assertSet('data.period_start', '2025-11-01')
            ->assertSet('data.period_end', '2025-11-30')
            // Las fechas siguen siendo editables manualmente.
            ->fillForm(['period_start' => '2025-11-05'])
            ->assertSet('data.period_start', '2025-11-05');
    }

    public function test_create_period_rejects_future_months_and_years(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 15));
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([[2026, 7], [2027, 1]] as [$year, $month]) {
            Livewire::test(CreateAccountingPeriod::class)
                ->fillForm(['year' => $year, 'month' => $month, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30'])
                ->call('create')
                ->assertHasFormErrors(['month'])
                ->assertSee('No se pueden crear cierres para el futuro');
        }

        $this->assertDatabaseCount('accounting_periods', 0);
    }

    public function test_close_modal_is_read_only_and_execution_delegates_to_service(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $period = AccountingPeriod::query()->create(['year' => 2026, 'month' => 10, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'open']);
        Expense::factory()->registered()->create(['expense_date' => '2026-10-01', 'due_date' => '2026-10-15']);
        $mock = $this->mock(MonthlyClosingService::class);
        $mock->shouldReceive('closePeriod')->once()->with(2026, 10, $admin->id)->andReturn($period);
        $page = Livewire::test(ListAccountingPeriods::class)->mountTableAction('close_period', $period);
        $this->assertStringContainsString('Vista previa: 1 gastos', $page->instance()->getMountedAction()->getModalDescription());
        $this->assertStringContainsString('Vista previa: 1 gastos', AccountingPeriodsTable::closingPreview($period));
        $this->assertSame('open', $period->fresh()->status->value);
        $page->callMountedTableAction()->assertNotified('Período cerrado');
    }

    public function test_owner_sees_only_own_consolidated_balance_and_read_only_detail(): void
    {
        Role::findOrCreate('propietario', 'web');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $this->actingAs($owner->user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $charge = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'expense_id' => null, 'charge_type' => 'balance_consolidation', 'consolidation_year' => 2026, 'consolidation_month' => 10, 'amount' => 12345]);
        $source = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 12345, 'status' => 'consolidated']);
        $charge->consolidationLinks()->create(['source_charge_id' => $source->id, 'transferred_amount' => 12345]);
        $other = PartnerCharge::factory()->create(['charge_type' => 'balance_consolidation', 'expense_id' => null, 'consolidation_year' => 2026, 'consolidation_month' => 10]);

        Livewire::test(ListMisCobros::class)->assertCanSeeTableRecords([$charge])->assertCanNotSeeTableRecords([$other])->assertSee('Saldo octubre 2026');
        $this->assertFalse(MisCobrosResource::canView($other));
        $this->assertFalse(MisCobrosResource::canEdit($charge));
        $this->assertFalse(MisCobrosResource::canCreate());
        Livewire::test(ViewCobro::class, ['record' => $charge->id])->assertOk()->assertSee('Saldo octubre 2026')->assertSee('Saldo transferido');
        $nextBalance = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'charge_type' => 'balance_consolidation', 'expense_id' => null, 'consolidation_year' => 2026, 'consolidation_month' => 11]);
        $charge->update(['status' => 'consolidated']);
        $nextBalance->consolidationLinks()->create(['source_charge_id' => $charge->id, 'transferred_amount' => $charge->remaining_amount]);
        $this->assertSame('Consolidado en noviembre 2026', MisCobrosResource::consolidationLabel($charge->fresh()));
    }

    public function test_reopen_surfaces_service_dependency_reason(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $period = AccountingPeriod::query()->create(['year' => 2026, 'month' => 10, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'closed']);
        $this->mock(MonthlyClosingService::class)->shouldReceive('reopenPeriod')->once()->andThrow(new \DomainException('Dependencia posterior de prueba'));
        Livewire::test(ListAccountingPeriods::class)
            ->callTableAction('reopen_period', $period, ['reason' => 'Corrección'])
            ->assertNotified(Notification::make()->title('No se pudo reabrir el período')->body('Dependencia posterior de prueba')->danger());
    }
}
