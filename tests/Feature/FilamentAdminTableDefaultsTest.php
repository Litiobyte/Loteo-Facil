<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\CollectionResource;
use App\Filament\Admin\Resources\CollectionResource\Pages\ListCollections;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\ManageCommunitySettings;
use App\Filament\Admin\Resources\ExpenseCategoryResource\Pages\ManageExpenseCategories;
use App\Filament\Admin\Resources\ExpenseFundingPayments\Pages\ListExpenseFundingPayments;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ListPartnerCharges;
use App\Filament\Admin\Resources\ShareHoldingResource\Pages\ManageShareHoldings;
use App\Models\Collection;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAdminTableDefaultsTest extends TestCase
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

    public function test_admin_panel_uses_full_content_width(): void
    {
        $this->assertSame(Width::Full, Filament::getPanel('admin')->getMaxContentWidth());
    }

    public function test_admin_tables_offer_search_column_selection_and_sorting(): void
    {
        foreach ([
            ManageShareHoldings::class,
            ManageCommunitySettings::class,
            ListPartnerCharges::class,
            ListCollections::class,
            ManageExpenseCategories::class,
            ListExpenses::class,
            ListExpenseFundingPayments::class,
            ListAccountingPeriods::class,
        ] as $page) {
            $table = Livewire::test($page)->assertOk()->instance()->getTable();

            $this->assertTrue($table->isSearchable(), $page);
            $this->assertTrue($table->hasColumnManager(), $page);
            $this->assertNotEmpty(array_filter($table->getColumns(), fn ($column): bool => $column->isSortable()), $page);

            foreach ($table->getColumns() as $column) {
                $this->assertSame('fit-content', $column->getWidth(), $page);
                $this->assertSame('width: fit-content; max-width: 15.625rem; overflow-wrap: anywhere;', $column->getExtraCellAttributes()['style'], $page);

                if ($column instanceof TextColumn) {
                    $this->assertTrue($column->canWrap(), $page);
                    $this->assertNull($column->getCharacterLimit(), $page);
                }
            }
        }
    }

    public function test_collection_bulk_delete_preserves_payments_with_allocations(): void
    {
        $charge = PartnerCharge::factory()->create(['amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100]);
        $allocated = Collection::factory()->create(['propietario_id' => $charge->propietario_id, 'amount' => 100]);
        CollectionResource::applyAutomatically($allocated);
        $deletable = Collection::factory()->create();

        Livewire::test(ListCollections::class)
            ->callTableBulkAction('delete', [$allocated, $deletable]);

        $this->assertModelExists($allocated);
        $this->assertModelMissing($deletable);
        $this->assertSame(1, $allocated->allocations()->count());
    }

    public function test_share_table_searches_and_sorts_real_owner_name_columns(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares', 'square_meters_per_share' => '800', 'share_precision' => 4, 'authorized_shares' => null,
        ], 'Prueba de tabla', auth()->user());
        $owner = Propietario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Zeta']);
        $other = Propietario::factory()->create(['nombre' => 'Beto', 'apellido' => 'Alfa']);
        $service = app(ShareHoldingService::class);
        $first = $service->acquire($owner, '10', today()->toDateString(), 'table-first', 'Prueba', auth()->user());
        $second = $service->acquire($other, '20', today()->toDateString(), 'table-second', 'Prueba', auth()->user());

        Livewire::test(ManageShareHoldings::class)
            ->sortTable('propietario.nombre_completo')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->sortTable('propietario.nombre_completo', 'desc')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->searchTable('Zeta')
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    }

    public function test_protected_tables_do_not_offer_bulk_deletion(): void
    {
        foreach ([ManageShareHoldings::class, ManageCommunitySettings::class, ListPartnerCharges::class, ListExpenseFundingPayments::class] as $page) {
            Livewire::test($page)->assertTableBulkActionDoesNotExist('delete');
        }
    }
}
