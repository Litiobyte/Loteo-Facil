<?php

namespace Tests\Feature;

use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Filament\Admin\Resources\ExpenseResource\RelationManagers\ChargesRelationManager;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ListPartnerCharges;
use App\Filament\Admin\Resources\PartnerChargeResource\Pages\ViewPartnerCharge;
use App\Filament\Owner\Pages\Dashboard;
use App\Filament\Owner\Pages\EstadoDeCuenta;
use App\Filament\Owner\Resources\Cobros\Pages\ListMisCobros;
use App\Filament\Owner\Resources\Cobros\Pages\ViewCobro;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use App\Support\ChargeSettlementPresentation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NonMonetaryPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ownerUser;

    private Propietario $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->ownerUser = User::factory()->create();
        $this->ownerUser->assignRole('propietario');
        $this->owner = Propietario::factory()->create(['user_id' => $this->ownerUser->id]);
    }

    public function test_admin_table_detail_and_expense_relation_distinguish_adjusted_charge(): void
    {
        $charge = $this->settledCharge();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPartnerCharges::class)
            ->assertTableColumnStateSet('collection_applied_amount', 0, $charge)
            ->assertTableColumnStateSet('non_monetary_applied_amount', 10000, $charge)
            ->assertSee('Ajustado (sin recaudación)')
            ->assertSee('Aplicado como ajuste no monetario');
        Livewire::test(ViewPartnerCharge::class, ['record' => $charge->id])
            ->assertSee('Ajustado (sin recaudación)')
            ->assertSee('Total aplicado (pagos y ajustes)')
            ->assertSee('Pagado con recaudaciones')->assertSee('Aplicado como ajuste no monetario');
        Livewire::test(ChargesRelationManager::class, [
            'ownerRecord' => $charge->expense, 'pageClass' => ViewExpense::class,
        ])->assertTableColumnStateSet('collection_applied_amount', 0, $charge)
            ->assertTableColumnStateSet('non_monetary_applied_amount', 10000, $charge)
            ->assertSee('Ajustado (sin recaudación)');
    }

    public function test_owner_list_detail_and_csv_do_not_call_adjustments_payments(): void
    {
        $charge = $this->settledCharge();
        $other = PartnerCharge::factory()->create(['description' => 'Privado de otro propietario']);
        $this->actingAs($this->ownerUser);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $page = Livewire::test(ListMisCobros::class)
            ->assertViewHas('paidTotal', 0.0)
            ->assertViewHas('nonMonetaryTotal', 10000.0)
            ->assertSee('Ajustado (sin recaudación)')->assertSee('Ajuste no monetario:')
            ->assertDontSee($other->description);
        Livewire::test(ViewCobro::class, ['record' => $charge->id])
            ->assertSee('Ajustado (sin recaudación)')->assertSee('Aplicado como ajuste no monetario')
            ->assertDontSee('Historial de Recaudaciones');
        $csv = TestResponse::fromBaseResponse($page->instance()->exportCsv())->streamedContent();
        $rows = $this->csvRows($csv, ';');
        $this->assertSame('Pagado con recaudaciones', $rows[0][6]);
        $this->assertSame('Aplicado como ajuste no monetario', $rows[0][7]);
        $this->assertSame(0.0, (float) $rows[1][6]);
        $this->assertSame(10000.0, (float) $rows[1][7]);
        $this->assertSame('Ajustado (sin recaudación)', $rows[1][9]);
        $this->assertStringNotContainsString($other->description, $csv);
    }

    public function test_admin_csv_separates_recaudations_and_adjustments_for_mixed_settlement(): void
    {
        $charge = $this->settledCharge(2500);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $records = PartnerChargeResource::getEloquentQuery()->whereKey($charge->id)->get();
        $response = (new ReflectionMethod(PartnerChargeResource::class, 'exportSelectedToCsv'))->invoke(null, $records);
        $rows = $this->csvRows(TestResponse::fromBaseResponse($response)->streamedContent(), ',');
        $this->assertSame('Total aplicado (pagos y ajustes)', $rows[0][4]);
        $this->assertSame('Pagado con recaudaciones', $rows[0][5]);
        $this->assertSame('Aplicado como ajuste no monetario', $rows[0][6]);
        $this->assertSame(10000.0, (float) $rows[1][4]);
        $this->assertSame(2500.0, (float) $rows[1][5]);
        $this->assertSame(7500.0, (float) $rows[1][6]);
        $this->assertSame('Saldado con recaudaciones y ajustes', $rows[1][8]);
        Livewire::test(ListPartnerCharges::class)
            ->assertSee('Saldado con recaudaciones y ajustes')
            ->callTableBulkAction('export_selected_csv', [$charge])
            ->assertFileDownloaded();
    }

    public function test_owner_statement_print_summary_and_dashboard_use_domain_breakdown(): void
    {
        $this->settledCharge(2500);
        $this->actingAs($this->ownerUser);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::test(EstadoDeCuenta::class)
            ->assertViewHas('resumen', fn (array $summary): bool => $summary['aplicado'] === 2500.0 && $summary['ajustado'] === 7500.0)
            ->assertSee('Pagado con recaudaciones')
            ->assertSee('Aplicado como ajuste no monetario')
            ->assertSee('Los ajustes no monetarios no son pagos ni ingresos de caja.')
            ->assertSee('Ajuste histórico')
            ->call('setQuickFilter', 'this_month')
            ->assertViewHas('resumen', fn (array $summary): bool => $summary['ajustado'] === 7500.0)
            ->assertSee('Imprimir / PDF');
        Livewire::test(Dashboard::class)->assertSee('Aplicado como ajuste no monetario')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total_applied'] === 2500.0 && $summary['total_non_monetary_applied'] === 7500.0);
    }

    public function test_payment_only_and_legacy_without_evidence_are_not_misclassified(): void
    {
        $charge = $this->settledCharge(10000);
        $record = ChargeSettlementPresentation::withAmounts(PartnerCharge::query())->findOrFail($charge->id);
        $this->assertSame('Pagado con recaudaciones', ChargeSettlementPresentation::statusLabel($record));
        $this->assertSame(0.0, (float) $record->non_monetary_applied_amount);
        $legacy = PartnerCharge::factory()->create(['paid_amount' => 10000, 'amount' => 10000, 'status' => 'paid']);
        $legacy = ChargeSettlementPresentation::withAmounts(PartnerCharge::query())->findOrFail($legacy->id);
        $this->assertSame('Saldado (sin desglose)', ChargeSettlementPresentation::statusLabel($legacy));
        $this->assertSame(0.0, (float) $legacy->collection_applied_amount);
    }

    public function test_partial_adjustment_is_not_labelled_as_partial_payment(): void
    {
        $credit = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-10000.00', '2020-01-01', ['source' => 'Libro histórico']);
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->owner->id,
            'expense_id' => Expense::factory()->create(['due_date' => null])->id,
            'amount' => 10000, 'paid_amount' => 0, 'status' => 'pending',
            'created_at' => '2020-02-01', 'due_date' => today()->addDays(5),
        ]);
        app(HistoricalAdjustmentService::class)->applyCredit($credit, $charge, '2500.00', today()->toDateString(), $this->admin);
        $this->actingAs($this->ownerUser);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::test(ListMisCobros::class)->assertSee('Parcial · con ajustes')
            ->assertViewHas('paidTotal', 0.0)->assertViewHas('nonMonetaryTotal', 2500.0);
        Livewire::test(EstadoDeCuenta::class)->assertSee('Parcial · con ajustes');
        Livewire::test(Dashboard::class)->assertSee('Aplicación parcial')->assertDontSee('Pago parcial');
    }

    public function test_projection_is_batched_and_excludes_cancelled_recaudations(): void
    {
        $charge = $this->settledCharge(10000);
        Collection::query()->where('propietario_id', $this->owner->id)->update(['status' => 'cancelled']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $records = ChargeSettlementPresentation::withAmounts(PartnerCharge::query())->get();
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $record = $records->firstWhere('id', $charge->id);
        $this->assertSame(0.0, (float) $record->collection_applied_amount);
    }

    private function settledCharge(int $cashAmount = 0): PartnerCharge
    {
        $credit = $cashAmount < 10000
            ? app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '-10000.00', '2020-01-01', ['source' => 'Libro histórico'])
            : null;
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->owner->id,
            'expense_id' => Expense::factory()->create(['due_date' => null])->id,
            'amount' => 10000, 'paid_amount' => 0, 'status' => 'pending',
            'created_at' => '2020-02-01', 'description' => 'Cobro de prueba de desglose',
        ]);
        if ($cashAmount > 0) {
            $payment = Collection::factory()->create([
                'propietario_id' => $this->owner->id, 'amount' => $cashAmount,
                'applied_amount' => 0, 'status' => 'pending_application', 'collection_date' => today(),
            ]);
            app(CollectionApplicationService::class)->applyCollectionManually($payment, [['charge_id' => $charge->id, 'amount' => $cashAmount]]);
        }
        if ($credit) {
            app(HistoricalAdjustmentService::class)->applyCredit($credit, $charge, number_format(10000 - $cashAmount, 2, '.', ''), today()->toDateString(), $this->admin);
        }

        return $charge->refresh();
    }

    /** @return list<array<int, string|null>> */
    private function csvRows(string $csv, string $delimiter): array
    {
        return array_map(fn (string $line): array => str_getcsv($line, $delimiter, '"', ''), explode("\n", trim($csv)));
    }
}
