<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Admin\Resources\CollectionResource\Pages\ListCollections;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\ManageCommunitySettings;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Etapa;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use App\Support\ChileanNumber;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChileanNumberInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_decimal_storage_and_localized_input_have_distinct_boundaries(): void
    {
        $this->assertSame('1.000.000', ChileanNumber::format('1000000.00'));
        $this->assertSame('1.000,125', ChileanNumber::format('1000.1250'));
        $this->assertSame('1000000', ChileanNumber::normalize('1.000.000'));
        $this->assertSame('4000', ChileanNumber::normalize('4000'));
        $this->assertSame('1000.125', ChileanNumber::normalize('1.000,125'));
        $this->assertSame('0', ChileanNumber::normalize('0'));
        $this->assertSame('-1000', ChileanNumber::normalize('-1.000'));
        $this->assertSame('1e6', ChileanNumber::normalize('1e6'));
    }

    public function test_creates_and_edits_large_expense_without_rescaling(): void
    {
        $category = ExpenseCategory::factory()->create();

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'title' => 'Importe localizado',
                'expense_category_id' => $category->id,
                'expense_date' => today()->toDateString(),
                'distribution_type' => 'equal_by_partner',
            ])
            ->set('data.amount', '1.000.000')
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::query()->where('title', 'Importe localizado')->sole();
        $this->assertSame('1000000.00', $expense->amount);

        Livewire::test(EditExpense::class, ['record' => $expense->id])
            ->assertSet('data.amount', '1.000.000')
            ->set('data.amount', '2.000.000')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2000000.00', $expense->fresh()->amount);

        Livewire::test(EditExpense::class, ['record' => $expense->id])
            ->fillForm(['notes' => 'Sin cambio de monto'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2000000.00', $expense->fresh()->amount);
    }

    public function test_rejects_fractional_scientific_zero_and_negative_new_expenses(): void
    {
        $category = ExpenseCategory::factory()->create();

        foreach (['1,5', '1e6', '0', '-1.000', '1.00.000'] as $amount) {
            Livewire::test(CreateExpense::class)
                ->fillForm([
                    'title' => 'Inválido',
                    'expense_category_id' => $category->id,
                    'expense_date' => today()->toDateString(),
                    'distribution_type' => 'equal_by_partner',
                ])
                ->set('data.amount', $amount)
                ->call('create')
                ->assertHasFormErrors(['amount']);
        }

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_editing_notes_preserves_fractional_historical_amount_even_if_tampered(): void
    {
        $expense = Expense::factory()->create(['amount' => '1234.56', 'distribution_type' => 'equal_by_partner']);

        Livewire::test(EditExpense::class, ['record' => $expense->id])
            ->fillForm(['notes' => 'Conservar histórico'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1234.56', $expense->fresh()->amount);
        $this->assertSame('Conservar histórico', $expense->fresh()->notes);

        Livewire::test(EditExpense::class, ['record' => $expense->id])
            ->set('data.amount', '1.235')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1234.56', $expense->fresh()->amount);
    }

    public function test_lot_surface_keeps_fraction_and_money_uses_integer_groups(): void
    {
        $etapa = Etapa::query()->create(['numero' => 1, 'nombre' => 'Prueba']);
        $lote = Lote::factory()->create(['metros_cuadrados' => '1000.25', 'valor_lote' => '1000000.00', 'etapa_id' => $etapa->id]);

        Livewire::test(ViewLote::class, ['record' => $lote->id])
            ->assertSet('data.metros_cuadrados', '1.000,25')
            ->assertSet('data.valor_lote', '1.000.000')
            ->set('data.metros_cuadrados', '2.500,75')
            ->set('data.valor_lote', '4.000.000')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2500.75', (string) $lote->fresh()->metros_cuadrados);
        $this->assertSame('4000000', (string) $lote->fresh()->valor_lote);
    }

    public function test_collection_and_manual_allocation_keep_localized_millions(): void
    {
        $owner = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id, 'amount' => '1000000', 'paid_amount' => '0',
        ]);

        Livewire::test(CreateCollection::class)
            ->fillForm([
                'propietario_id' => $owner->id,
                'collection_date' => today()->toDateString(),
                'collection_method' => 'transferencia',
            ])
            ->set('data.amount', '2.000.000')
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = Collection::query()->sole();
        $this->assertSame('2000000.00', $collection->amount);
        $this->assertSame('2000000.00', $collection->unapplied_amount);

        Livewire::test(ListCollections::class)
            ->mountTableAction('apply_manually', $collection)
            ->set('mountedActions.0.data.allocations', ['one' => ['charge_id' => $charge->id, 'amount' => '1.000.000']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('1000000.00', $collection->fresh()->applied_amount);
        $this->assertSame('1000000.00', $collection->fresh()->unapplied_amount);
        $this->assertSame('1000000.00', $charge->fresh()->paid_amount);
    }

    public function test_surface_configuration_uses_groups_without_losing_fraction_on_reopening(): void
    {
        $settings = CommunitySetting::current();

        Livewire::test(ManageCommunitySettings::class)
            ->callTableAction('configure', $settings, [
                'mode' => 'shares', 'square_meters_per_share' => '1.000,125',
                'share_precision' => 4, 'authorized_shares' => null, 'reason' => 'Superficie localizada',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('1000.1250', $settings->fresh()->square_meters_per_share);

        Livewire::test(ManageCommunitySettings::class)
            ->mountTableAction('configure', $settings->fresh())
            ->assertSet('mountedActions.0.data.square_meters_per_share', '1.000,125')
            ->set('mountedActions.0.data.reason', 'Sin modificar superficie')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('1000.1250', $settings->fresh()->square_meters_per_share);
    }
}
