<?php

namespace Tests\Feature;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Filament\Admin\Pages\Imports\ImportFinancial;
use App\Filament\Admin\Pages\Imports\ImportHistory;
use App\Models\Collection;
use App\Models\DataImport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\HistoricalExternalIdentity;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialImportUiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_super_admin_can_upload_map_preview_confirm_and_execute_financial_import(): void
    {
        $owner = Propietario::factory()->create();
        $this->assertTrue(ImportFinancial::canAccess());
        $this->assertTrue(ImportFinancial::shouldRegisterNavigation());
        $page = $this->upload("book,opening_balance,OPEN-1,{$owner->id},1000.00,2020-01-31")
            ->assertSet('step', 2)->assertDontSee('Actualizar campos permitidos');
        $batch = DataImport::query()->sole();
        Storage::disk('local')->assertExists($batch->path);
        $this->assertSame('financial', $batch->type);
        $page->call('preview')->assertHasNoErrors()->assertSet('step', 3)
            ->assertSee('El lote financiero es atómico');
        $this->assertSame(0, PartnerCharge::query()->count());
        $page->call('review')->assertSet('step', 4)->call('execute')->assertHasErrors('confirmed');
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Creado (created)')->assertSee('Movimiento registrado.')->assertDontSee('Reintentar filas fallidas');
        $this->assertSame('created', $batch->rows()->sole()->result);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->created_count);
        $this->assertSame(1, PartnerCharge::query()->count());
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors();
        $this->assertSame(1, PartnerCharge::query()->count());
        $page->call('downloadReport')->assertFileDownloaded("importacion-{$batch->id}.csv");
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)
            ->assertSet('step', 4)->assertSee('Movimiento registrado.');
        Livewire::test(ImportHistory::class)->assertCanSeeTableRecords([$batch])
            ->call('showDetails', $batch->id)->assertSee('Creado (created)')->assertSee('Movimiento registrado.');
    }

    public function test_admin_cannot_see_or_access_financial_page_or_history_details(): void
    {
        $owner = Propietario::factory()->create();
        $this->upload("book,opening_balance,OPEN-1,{$owner->id},1000.00,2020-01-31");
        $batch = DataImport::query()->sole();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->assertFalse(ImportFinancial::canAccess());
        $this->get(ImportFinancial::getUrl())->assertForbidden()->assertDontSee('Importar movimientos financieros');
        Livewire::test(ImportFinancial::class)->assertForbidden();
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)->assertForbidden();
        Livewire::test(ImportHistory::class)->assertCanNotSeeTableRecords([$batch])
            ->call('showDetails', $batch->id)->assertForbidden();
        $this->assertSame(0, PartnerCharge::query()->count());
    }

    public function test_role_revoked_after_mount_cannot_upload_execute_or_retry(): void
    {
        $page = Livewire::test(ImportFinancial::class);
        $this->superAdmin->syncRoles(['admin']);
        $page->call('uploadFile')->assertForbidden();
        $this->superAdmin->syncRoles(['super_admin']);
        $owner = Propietario::factory()->create();
        $page = $this->upload("book,opening_balance,OPEN-1,{$owner->id},1000.00,2020-01-31")
            ->call('preview')->call('review')->set('confirmed', true);
        $this->superAdmin->syncRoles(['admin']);
        $page->call('execute')->assertForbidden();
        $this->assertSame(0, PartnerCharge::query()->count());
    }

    public function test_invalid_mapping_is_rejected_and_preview_rows_can_be_corrected(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->upload("book,opening_balance,OPEN-1,{$owner->id},invalid,2020-01-31");
        $page->set('mapping.amount', 999)->call('preview')->assertHasErrors('mapping')
            ->set('mapping.amount', 4)->call('preview')->assertHasNoErrors()->assertSee('Corregir datos');
        $row = DataImport::query()->sole()->rows()->sole();
        $page->call('editRow', $row->id)->set('editingData.amount', '1000.00')->call('saveRow')
            ->assertHasNoErrors()->assertSet('step', 3);
        $this->assertSame('1000.00', $row->refresh()->data['amount']);
        $this->assertSame([], $row->errors);
        $this->assertSame(0, PartnerCharge::query()->count());
    }

    public function test_atomic_failure_then_ui_retry_updates_only_failed_rows(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->upload("book,opening_balance,OPEN-1,{$owner->id},invalid,2020-01-31")
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Fallido (failed)')->assertSee('Reintentar filas fallidas')->assertSet('confirmed', false);
        $batch = DataImport::query()->sole();
        $failed = $batch->rows()->sole();
        $this->assertSame('completed_with_errors', $batch->refresh()->status);
        $this->assertSame(0, PartnerCharge::query()->count());
        $confirmed = $batch->rows()->create([
            'number' => 3, 'raw' => [], 'data' => ['invalid' => 'must not be inspected'],
            'result' => 'created', 'changes' => ['result_message' => ['before' => null, 'after' => 'Resultado previo intacto.']],
        ]);
        $skipped = $batch->rows()->create([
            'number' => 4, 'raw' => [], 'data' => ['invalid' => 'must not be inspected'],
            'result' => 'skipped', 'changes' => ['result_message' => ['before' => null, 'after' => 'Omisión previa intacta.']],
        ]);
        $batch->update(['total' => 3]);
        $beforeCreated = $confirmed->refresh()->getAttributes();
        $beforeSkipped = $skipped->refresh()->getAttributes();
        $failed->update(['data' => array_replace($failed->data, ['amount' => '1000.00'])]);
        $page->call('retryFailed')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('retryFailed')->assertHasNoErrors()
            ->assertSee('Movimiento registrado.')->assertSee('Omitido (skipped)')
            ->assertSee('Resultado previo intacto.')->assertDontSee('Reintentar filas fallidas');
        $this->assertSame('created', $failed->refresh()->result);
        $this->assertSame($beforeCreated, $confirmed->refresh()->getAttributes());
        $this->assertSame($beforeSkipped, $skipped->refresh()->getAttributes());
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(2, $batch->created_count);
        $this->assertSame(1, $batch->skipped_count);
        $this->assertSame(0, $batch->error_count);
        $this->assertSame(1, PartnerCharge::query()->count());
        $page->set('confirmed', true)->call('retryFailed')->assertForbidden();
    }

    public function test_other_super_admin_cannot_resume_or_execute_someone_elses_batch(): void
    {
        $owner = Propietario::factory()->create();
        $this->upload("book,opening_balance,OPEN-1,{$owner->id},1000.00,2020-01-31");
        $other = User::factory()->create();
        $other->assignRole('super_admin');
        $this->actingAs($other);
        Livewire::withQueryParams(['import' => DataImport::query()->sole()->id])->test(ImportFinancial::class)->assertForbidden();
    }

    public function test_super_admin_imports_explicit_expenses_from_csv_json_and_can_resume(): void
    {
        $owner = Propietario::factory()->create();
        $charges = json_encode([['external_id' => 'C1', 'propietario_id' => $owner->id, 'amount' => '100.00']], JSON_THROW_ON_ERROR);
        $page = $this->uploadExpense(['mode' => 'explicit', 'charges' => $charges])
            ->assertSet('step', 2)->assertSee('Cobros explícitos JSON')
            ->call('preview')->assertHasNoErrors()->assertSet('step', 3);
        $batch = DataImport::query()->sole();
        $this->assertDatabaseCount('expenses', 0);
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)
            ->assertSet('financialType', 'expenses')->assertSee('Cobros explícitos JSON');
        $page->call('review')->call('execute')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()->assertSee('Creado (created)');
        $this->assertSame('created', $batch->rows()->sole()->result);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame('100.00', PartnerCharge::query()->sole()->amount);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('collections', 0);
        $page->call('selectFinancialType', 'adjustments')->assertForbidden();
    }

    public function test_expense_json_validation_and_excess_amount_errors_are_visible_and_correctable(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->uploadExpense(['mode' => 'explicit', 'charges' => 'invalid JSON'])
            ->call('preview')->assertSee('Los cobros explícitos deben ser una lista JSON.');
        $row = DataImport::query()->sole()->rows()->sole();
        $page->call('editRow', $row->id)->assertSee('textarea', false)
            ->set('editingData.charges', json_encode([['external_id' => 'C1', 'propietario_id' => $owner->id, 'amount' => '101.00']]))
            ->call('saveRow')->assertHasNoErrors()->assertSee('Los cobros explícitos exceden el monto del gasto.');
        $page->call('editRow', $row->id)
            ->set('editingData.charges', json_encode([['external_id' => 'C1', 'propietario_id' => $owner->id, 'amount' => '75.00']]))
            ->call('saveRow')->assertHasNoErrors()->assertSee('información histórica incompleta')
            ->call('review')->set('confirmed', true)->call('execute')->assertSee('Creado (created)');
        $this->assertSame('75.00', PartnerCharge::query()->sole()->amount);
    }

    public function test_expense_distribution_warning_and_explicit_marker_are_required(): void
    {
        $page = $this->uploadExpense(['mode' => 'distribute'])
            ->assertSee('Reconstrucción aproximada, no historia verificada')
            ->assertSee('distribuir_con_datos_actuales=sí')
            ->call('preview')->assertSee('Confirme explícitamente')
            ->call('review')->set('confirmed', true)->call('execute')->assertSee('Fallido (failed)');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('failed', DataImport::query()->sole()->rows()->sole()->result);
    }

    public function test_expense_distribution_with_explicit_marker_uses_domain_service(): void
    {
        $owner = Propietario::factory()->create();
        $lot = Lote::factory()->create(['metros_cuadrados' => 10000]);
        $owner->lotes()->attach($lot->id, ['assigned_at' => now(), 'status' => 'active']);
        $this->uploadExpense(['mode' => 'distribute', 'distribuir_con_datos_actuales' => 'sí'])
            ->call('preview')->assertHasNoErrors()->assertSee('no historia verificada')
            ->call('review')->set('confirmed', true)->call('execute')->assertSee('Creado (created)');
        $charge = PartnerCharge::query()->sole();
        $this->assertNotEmpty($charge->distribution_snapshot);
        $this->assertSame('2020-01-10', $charge->imputation_date->toDateString());
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_expense_conflict_is_reported_as_manual_review_not_success_or_automatic_retry(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->uploadExpense(['expense_category_id' => $category->id])
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')->assertSee('Creado (created)');
        $this->uploadExpense(['expense_category_id' => $category->id])
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')->assertSee('Omitido (skipped)');
        $page = $this->uploadExpense(['expense_category_id' => $category->id, 'amount' => '101.00'])
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Conflicto (conflict)')->assertSee('requieren revisión manual')
            ->assertDontSee('Reintentar filas fallidas');
        $batch = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame('conflict', $batch->rows()->sole()->result);
        $this->assertSame('completed_with_errors', $batch->status);
        $this->assertSame(1, $batch->error_count);
        $this->assertDatabaseCount('expenses', 1);
        $page->call('downloadReport', true)->assertFileDownloaded("importacion-{$batch->id}-errores.csv");
        Livewire::test(ImportHistory::class)->call('showDetails', $batch->id)
            ->assertSee('Conflicto (conflict)')->assertSee('contenido distinto');
        $page->set('confirmed', true)->call('retryFailed')->assertForbidden();
    }

    public function test_admin_cannot_select_or_execute_expense_import(): void
    {
        $page = $this->uploadExpense()->call('preview')->call('review')->set('confirmed', true);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->get(ImportFinancial::getUrl())->assertForbidden()->assertDontSee('Gastos históricos');
        Livewire::test(ImportFinancial::class)->assertForbidden();
        $page->call('execute')->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_super_admin_imports_opening_charges_and_resumes_with_definition_mapping(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->uploadCharge(['propietario_id' => $owner->id])
            ->assertSet('step', 2)->assertSee('Modalidad (linked, backing, opening)')
            ->call('preview')->assertHasNoErrors()->assertSet('step', 3);
        $batch = DataImport::query()->sole();
        $this->assertSame(array_keys(app(ImportDefinition::class)->fields('financial_charges')), array_keys($page->get('mapping')));
        $this->assertDatabaseCount('partner_charges', 0);
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)
            ->assertSet('financialType', 'charges')->assertSee('Modalidades de cobros históricos');
        $page->call('review')->call('execute')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()->assertSee('Creado (created)');
        $this->assertSame('created', $batch->rows()->sole()->result);
        $this->assertSame('opening_balance', PartnerCharge::query()->sole()->charge_type);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_charge_reconciliation_warning_is_visible_in_results_history_and_skipped_rows(): void
    {
        $data = ['propietario_id' => Propietario::factory()->create()->id, 'reported_paid' => '50.00', 'reported_remaining' => '50.00'];
        $message = 'Advertencia: los importes informados difieren de las aplicaciones efectivas; no se registraron pagos.';
        $this->uploadCharge($data)->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Creado (created)')->assertSee($message);
        $batch = DataImport::query()->sole();
        $this->assertStringContainsString($message, $batch->rows()->sole()->changes['result_message']['after']);
        Livewire::test(ImportHistory::class)->call('showDetails', $batch->id)->assertSee($message);
        $this->uploadCharge($data)->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Omitido (skipped)')->assertSee($message);
        $this->assertDatabaseCount('partner_charges', 1);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_charge_identity_conflict_is_visible_and_not_retried(): void
    {
        $data = ['propietario_id' => Propietario::factory()->create()->id];
        $this->uploadCharge($data)->call('preview')->call('review')->set('confirmed', true)->call('execute');
        $page = $this->uploadCharge(array_replace($data, ['amount' => '101.00']))
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Conflicto (conflict)')->assertSee('contenido distinto')
            ->assertSee('requieren revisión manual')->assertDontSee('Reintentar filas fallidas');
        $batch = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame('conflict', $batch->rows()->sole()->result);
        $this->assertSame('completed_with_errors', $batch->status);
        $page->call('downloadReport', true)->assertFileDownloaded("importacion-{$batch->id}-errores.csv")
            ->set('confirmed', true)->call('retryFailed')->assertForbidden();
        $this->assertDatabaseCount('partner_charges', 1);
    }

    public function test_charge_backing_warning_and_mode_correction_use_existing_domain(): void
    {
        $page = $this->uploadCharge([
            'propietario_id' => Propietario::factory()->create()->id, 'mode' => 'invalid',
            'expense_external_id' => 'BACKING-1', 'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'title' => 'Respaldo histórico',
        ])->call('preview')->assertSee('no acredita el total histórico original');
        $row = DataImport::query()->sole()->rows()->sole();
        $page->call('editRow', $row->id)->assertSee('Gasto de respaldo histórico (backing)')
            ->set('editingData.mode', 'backing')->call('saveRow')->assertHasNoErrors()
            ->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Creado (created)')->assertSee('Respaldo técnico, no total histórico acreditado');
        $this->assertSame('created', $row->refresh()->result);
        $this->assertDatabaseHas('expenses', ['amount' => '100.00', 'historical_distribution_blocked' => true]);
        $expense = Expense::factory()->create(['amount' => '100.00', 'historical_distribution_blocked' => true, 'due_date' => null]);
        $linkedPage = $this->uploadCharge([
            'external_id' => 'LINKED-1', 'propietario_id' => Propietario::factory()->create()->id,
            'mode' => 'linked', 'expense_id' => (string) $expense->id,
        ])->call('preview')->assertHasNoErrors()->call('review')->set('confirmed', true)->call('execute');
        $linkedRow = DataImport::query()->latest('id')->firstOrFail()->rows()->sole();
        $this->assertSame('created', $linkedRow->result, json_encode($linkedRow->changes));
        $linkedPage->assertSee('Creado (created)');
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('partner_charges', 2);
    }

    public function test_admin_cannot_select_or_execute_historical_charges(): void
    {
        $initialPage = Livewire::test(ImportFinancial::class);
        $page = $this->uploadCharge(['propietario_id' => Propietario::factory()->create()->id])
            ->call('preview')->call('review')->set('confirmed', true);
        $this->superAdmin->syncRoles(['admin']);
        $this->get(ImportFinancial::getUrl())->assertForbidden()->assertDontSee('Cobros históricos');
        $initialPage->call('selectFinancialType', 'charges')->assertForbidden();
        $page->call('execute')->assertForbidden();
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_charge_mapping_requires_mode_and_origin_date(): void
    {
        $this->uploadCharge(['propietario_id' => Propietario::factory()->create()->id])
            ->set('mapping.mode', '')->call('preview')->assertHasErrors('mapping');
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_collection_automatic_flow_mapping_confirmation_and_resume(): void
    {
        $owner = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id, 'amount' => '100.00', 'paid_amount' => 0,
            'imputation_date' => '2020-01-01',
        ]);
        $page = $this->uploadCollection(['propietario_id' => $owner->id])
            ->assertSee('Aplicaciones JSON')
            ->call('preview')->assertHasNoErrors()->assertSet('step', 3);
        $batch = DataImport::query()->sole();
        $this->assertSame(array_keys(app(ImportDefinition::class)->fields('financial_collections')), array_keys($page->get('mapping')));
        $this->assertDatabaseCount('collections', 0);
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)
            ->assertSet('financialType', 'collections')->assertSee('Aplicación de recaudaciones históricas');
        $page->call('review')->call('execute')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()->assertSee('Creado (created)');
        $this->assertSame('created', $batch->rows()->sole()->result);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame('40.00', $charge->refresh()->paid_amount);
        $this->assertSame('2020-03-15', Collection::query()->sole()->allocations()->sole()->allocated_at->toDateString());
    }

    public function test_collection_manual_json_can_be_corrected_to_id_or_external_destination(): void
    {
        $owner = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id, 'amount' => '100.00', 'paid_amount' => 0, 'imputation_date' => '2020-01-01',
        ]);
        HistoricalExternalIdentity::query()->create([
            'community_scope' => '1', 'source' => 'ledger', 'movement_type' => 'expense_charge',
            'external_id' => 'C1', 'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
        ]);
        foreach ([['charge_id' => $charge->id], ['charge_external_id' => 'C1', 'charge_source' => 'ledger']] as $index => $destination) {
            $page = $this->uploadCollection([
                'propietario_id' => $owner->id, 'external_id' => 'P'.$index, 'mode' => 'manual', 'allocations' => 'invalid',
            ])->call('preview')->assertSee('Las aplicaciones deben ser una lista JSON.');
            $row = DataImport::query()->latest('id')->firstOrFail()->rows()->sole();
            $page->call('editRow', $row->id)->assertSee('Sin aplicación (unapplied)')->assertSee('textarea', false)
                ->set('editingData.allocations', json_encode([$destination + ['amount' => '40.00']]))
                ->call('saveRow')->assertHasNoErrors()->call('review')->set('confirmed', true)->call('execute')
                ->assertSee('Creado (created)');
            $this->assertSame('created', $row->refresh()->result);
        }
        $this->assertSame('80.00', $charge->refresh()->paid_amount);
    }

    public function test_collection_unknown_date_and_manual_excess_messages_are_visible(): void
    {
        $owner = Propietario::factory()->create();
        $this->uploadCollection(['propietario_id' => $owner->id, 'collection_date' => ''])
            ->call('preview')->assertSee('Se requiere fecha real completa')->assertSee('sin aplicación')
            ->call('review')->set('confirmed', true)->call('execute')->assertSee('Fallido (failed)');
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id, 'amount' => '10.00', 'paid_amount' => 0, 'imputation_date' => '2020-01-01',
        ]);
        $page = $this->uploadCollection([
            'propietario_id' => $owner->id, 'mode' => 'manual',
            'allocations' => json_encode([['charge_id' => $charge->id, 'amount' => '40.00']]),
        ])->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Fallido (failed)');
        $row = DataImport::query()->latest('id')->firstOrFail()->rows()->sole();
        $this->assertNotEmpty($row->errors);
        $page->assertSee($row->errors[0]);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_collection_cutoff_and_closed_period_rejections_are_visible(): void
    {
        $owner = Propietario::factory()->create();
        app(HistoricalOpeningService::class)->create(
            $owner, $this->superAdmin, '100.00', '2020-03-10', ['source' => 'ledger'],
        );
        $this->uploadCollection(['propietario_id' => $owner->id, 'mode' => 'unapplied', 'collection_date' => '2020-03-09'])
            ->call('preview')->assertSee('corte inclusivo de apertura')
            ->call('review')->set('confirmed', true)->call('execute')->assertSee('Fallido (failed)');
        app(MonthlyClosingService::class)->closePeriod(2020, 4);
        $page = $this->uploadCollection(['propietario_id' => $owner->id, 'mode' => 'unapplied', 'collection_date' => '2020-04-15'])
            ->call('preview');
        $row = DataImport::query()->latest('id')->firstOrFail()->rows()->sole();
        $this->assertNotEmpty($row->errors);
        $page->assertSee($row->errors[0])->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Fallido (failed)')->assertSee($row->errors[0]);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_collection_unapplied_skipped_and_conflict_results_preserve_manual_review(): void
    {
        $data = ['propietario_id' => Propietario::factory()->create()->id, 'mode' => 'unapplied'];
        $this->uploadCollection($data)->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Creado (created)');
        $this->uploadCollection($data)->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Omitido (skipped)');
        $page = $this->uploadCollection(array_replace($data, ['amount' => '41.00']))
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Conflicto (conflict)')->assertSee('requieren revisión manual')
            ->assertDontSee('Reintentar filas fallidas');
        $batch = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame('conflict', $batch->rows()->sole()->result);
        $page->call('downloadReport', true)->assertFileDownloaded("importacion-{$batch->id}-errores.csv")
            ->set('confirmed', true)->call('retryFailed')->assertForbidden();
        $this->assertSame('40.00', Collection::query()->sole()->unapplied_amount);
    }

    public function test_admin_cannot_select_or_execute_historical_collections(): void
    {
        $initialPage = Livewire::test(ImportFinancial::class);
        $page = $this->uploadCollection(['propietario_id' => Propietario::factory()->create()->id, 'mode' => 'unapplied'])
            ->call('preview')->call('review')->set('confirmed', true);
        $this->superAdmin->syncRoles(['admin']);
        $this->get(ImportFinancial::getUrl())->assertForbidden()->assertDontSee('Recaudaciones históricas');
        $initialPage->call('selectFinancialType', 'collections')->assertForbidden();
        $page->call('execute')->assertForbidden();
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_collection_mapping_requires_mode_and_real_date(): void
    {
        $this->uploadCollection(['propietario_id' => Propietario::factory()->create()->id])
            ->set('mapping.mode', '')->call('preview')->assertHasErrors('mapping')
            ->set('mapping.mode', 9)->set('mapping.collection_date', '')->call('preview')->assertHasErrors('mapping');
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_super_admin_imports_initial_balances_with_definition_mapping_confirmation_and_resume(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->uploadOpening($owner->id)
            ->assertSet('step', 2)->assertSee('Fuente de información obligatoria');
        $this->assertSame(11, count(app(ImportDefinition::class)->fields('financial_openings')));
        $this->assertSame(array_keys(app(ImportDefinition::class)->fields('financial_openings')), array_keys($page->get('mapping')));
        $page->set('mapping.information_source', '')->call('preview')->assertHasErrors('mapping')
            ->set('mapping.information_source', 10)->call('preview')->assertHasNoErrors()->assertSet('step', 3);
        $batch = DataImport::query()->sole();
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
        $page->call('review')->call('execute')->assertHasErrors('confirmed')
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Creado (created)')->assertSee('Movimiento registrado.')
            ->assertSee('no modifica la caja')->assertDontSee('Reintentar filas fallidas');
        $row = $batch->rows()->sole();
        $this->assertSame('created', $row->result);
        $this->assertSame('initial_opening', $row->data['movement_type']);
        $this->assertSame('Libro de administración anterior', $row->data['information_source']);
        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->created_count);
        $charge = PartnerCharge::query()->sole();
        $this->assertSame('opening_balance', $charge->charge_type);
        $this->assertSame('100.00', $charge->amount);
        $this->assertSame('Libro de administración anterior', json_decode($charge->calculation_notes, true)['information_source']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
        Livewire::withQueryParams(['import' => $batch->id])->test(ImportFinancial::class)
            ->assertSet('financialType', 'openings')->assertSee('Apertura financiera sin movimiento de caja');
        $page->call('downloadReport')->assertFileDownloaded("importacion-{$batch->id}.csv");
    }

    public function test_initial_balance_both_amounts_are_rejected_and_zero_is_skipped(): void
    {
        $owner = Propietario::factory()->create();
        $page = $this->uploadOpening($owner->id, ['opening_credit' => '20.00'])
            ->call('preview')->assertSee('Indique deuda o crédito inicial, no ambos.')
            ->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Fallido (failed)')->assertSee('Indique deuda o crédito inicial, no ambos.');
        $failedBatch = DataImport::query()->sole();
        $this->assertSame('failed', $failedBatch->rows()->sole()->result);
        $this->assertSame('completed_with_errors', $failedBatch->refresh()->status);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
        $this->uploadOpening($owner->id, ['opening_debt' => '0', 'external_id' => 'OPEN-UI-ZERO'])
            ->call('preview')->assertHasNoErrors()->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Omitido (skipped)')->assertSee('Saldo cero: no se generó movimiento.');
        $zeroBatch = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame('skipped', $zeroBatch->rows()->sole()->result);
        $this->assertSame('completed', $zeroBatch->refresh()->status);
        $this->assertSame(1, $zeroBatch->skipped_count);
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
        $this->assertDatabaseCount('historical_external_identities', 0);
    }

    public function test_initial_balance_conflict_requires_manual_review_and_is_not_retried(): void
    {
        $owner = Propietario::factory()->create();
        $this->uploadOpening($owner->id)->call('preview')->call('review')
            ->set('confirmed', true)->call('execute')->assertSee('Creado (created)');
        $page = $this->uploadOpening($owner->id, ['opening_debt' => '101.00'])
            ->call('preview')->call('review')->set('confirmed', true)->call('execute')
            ->assertSee('Conflicto (conflict)')->assertSee('contenido distinto')
            ->assertSee('requieren revisión manual')->assertDontSee('Reintentar filas fallidas');
        $batch = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame('conflict', $batch->rows()->sole()->result);
        $this->assertSame('completed_with_errors', $batch->refresh()->status);
        $this->assertSame(1, $batch->error_count);
        $this->assertDatabaseCount('partner_charges', 1);
        $page->call('downloadReport', true)->assertFileDownloaded("importacion-{$batch->id}-errores.csv")
            ->set('confirmed', true)->call('retryFailed')->assertForbidden();
    }

    public function test_admin_cannot_select_or_execute_initial_balance_import(): void
    {
        $initialPage = Livewire::test(ImportFinancial::class)->assertSee('Saldos iniciales');
        $owner = Propietario::factory()->create();
        $page = $this->uploadOpening($owner->id)->call('preview')->call('review')->set('confirmed', true);
        $this->superAdmin->syncRoles(['admin']);
        $this->get(ImportFinancial::getUrl())->assertForbidden()->assertDontSee('Saldos iniciales');
        $initialPage->call('selectFinancialType', 'openings')->assertForbidden();
        $page->call('execute')->assertForbidden();
        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertDatabaseCount('historical_adjustments', 0);
    }

    private function uploadOpening(int $ownerId, array $overrides = []): Testable
    {
        $data = array_replace(array_fill_keys(array_keys(app(ImportDefinition::class)->fields('financial_openings')), ''), [
            'source' => 'ledger', 'movement_type' => 'initial_opening', 'external_id' => 'OPEN-UI-1',
            'propietario_id' => $ownerId, 'cutoff_date' => '2020-01-31', 'opening_debt' => '100.00',
            'opening_credit' => '0', 'concept' => 'Saldo inicial', 'information_source' => 'Libro de administración anterior',
        ], $overrides);
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, array_keys($data), ',', '"', '');
        fputcsv($stream, array_values($data), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return Livewire::test(ImportFinancial::class)->assertSee('Saldos iniciales')
            ->call('selectFinancialType', 'openings')->assertSee('Fuente de información obligatoria')
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-saldos-iniciales.csv')
            ->set('file', UploadedFile::fake()->createWithContent('openings.csv', $csv))
            ->call('uploadFile')->assertHasNoErrors();
    }

    private function uploadCollection(array $overrides = []): Testable
    {
        $data = array_replace(array_fill_keys(array_keys(app(ImportDefinition::class)->fields('financial_collections')), ''), [
            'source' => 'ledger', 'movement_type' => 'collection', 'external_id' => 'P1',
            'mode' => 'automatic', 'amount' => '40.00', 'collection_date' => '2020-03-15', 'collection_method' => 'transferencia',
        ], $overrides);
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, array_keys($data), ',', '"', '');
        fputcsv($stream, array_values($data), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return Livewire::test(ImportFinancial::class)->assertSee('Recaudaciones históricas')
            ->call('selectFinancialType', 'collections')->assertSee('Fecha real obligatoria')
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-recaudaciones-historicas.csv')
            ->set('file', UploadedFile::fake()->createWithContent('collections.csv', $csv))
            ->call('uploadFile')->assertHasNoErrors();
    }

    private function uploadCharge(array $overrides = []): Testable
    {
        $data = array_replace(array_fill_keys(array_keys(app(ImportDefinition::class)->fields('financial_charges')), ''), [
            'source' => 'ledger', 'movement_type' => 'expense_charge', 'external_id' => 'C1',
            'mode' => 'opening', 'amount' => '100.00', 'origin_date' => '2020-01-10',
        ], $overrides);
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, array_keys($data), ',', '"', '');
        fputcsv($stream, array_values($data), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return Livewire::test(ImportFinancial::class)->assertSee('Cobros históricos')
            ->call('selectFinancialType', 'charges')->assertSee('no acredita el total histórico original')
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-cobros-historicos.csv')
            ->set('file', UploadedFile::fake()->createWithContent('charges.csv', $csv))
            ->call('uploadFile')->assertHasNoErrors();
    }

    private function uploadExpense(array $overrides = []): Testable
    {
        $data = array_replace([
            'source' => 'ledger', 'movement_type' => 'expense', 'external_id' => 'E1',
            'title' => 'Gasto histórico', 'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'expense_date' => '2020-01-10', 'amount' => '100.00',
            'distribution_type' => 'equal_by_partner', 'mode' => 'register_only', 'charges' => '',
        ], $overrides);
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, array_keys($data), ',', '"', '');
        fputcsv($stream, array_values($data), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return Livewire::test(ImportFinancial::class)->assertSee('Gastos históricos')
            ->call('selectFinancialType', 'expenses')->assertSee('Reconstrucción aproximada')
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-gastos-historicos.csv')
            ->set('file', UploadedFile::fake()->createWithContent('expenses.csv', $csv))
            ->call('uploadFile')->assertHasNoErrors();
    }

    private function upload(string $rows): Testable
    {
        return Livewire::test(ImportFinancial::class)
            ->set('file', UploadedFile::fake()->createWithContent('financial.csv', "source,movement_type,external_id,propietario_id,amount,effective_date\n{$rows}\n"))
            ->call('uploadFile')->assertHasNoErrors();
    }
}
