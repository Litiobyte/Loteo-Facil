<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\AdministrativeImportService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareContributionService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Pages\Imports\ImportHistory;
use App\Filament\Admin\Pages\Imports\ImportShares;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use App\Models\Propietario;
use App\Models\ShareContribution;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use App\Support\ChileanRut;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShareOpeningImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AdministrativeImportService $service;

    private function owner(): Propietario
    {
        $number = (string) fake()->unique()->numberBetween(10000000, 25000000);
        foreach (str_split('0123456789K') as $digit) {
            $rut = $number.'-'.$digit;
            if (ChileanRut::isValid($rut)) {
                return Propietario::factory()->create(['rut' => $rut]);
            }
        }
        throw new \LogicException('No se pudo generar un RUT válido.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares', 'square_meters_per_share' => '800',
            'share_precision' => 4, 'authorized_shares' => null,
        ], 'Configurar importación', $this->admin);
        $this->service = app(AdministrativeImportService::class);
    }

    /** @param array<int, array<int, string>> $rows */
    private function preview(array $rows, string $mode = 'skip'): DataImport
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['rut', 'quantity', 'amount', 'reference_date', 'notes'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        $import = $this->service->start(UploadedFile::fake()->createWithContent('acciones.csv', $content), 'shares', $this->admin);

        return $this->service->preview($import, app(ImportDefinition::class)->autoMap('shares', $import->headers), $mode, $this->admin);
    }

    public function test_imports_exact_opening_with_audit_and_retries_without_duplicates(): void
    {
        $owner = $this->owner();
        $rows = [[$owner->rut, '2.1250', '123456.78', '2026-01-01', 'Saldo inicial']];
        $import = $this->preview($rows);
        $this->assertSame(0, $import->error_count);
        $this->assertDatabaseCount('share_holdings', 0);
        $this->assertDatabaseCount('share_contributions', 0);
        $done = $this->service->execute($import, $this->admin, true);
        $this->assertSame('completed', $done->status);
        $this->assertSame(1, $done->created_count);
        $holding = ShareHolding::query()->sole();
        $this->assertSame('2.1250', $holding->quantity);
        $this->assertSame('800.0000', $holding->square_meters_per_share);
        $this->assertSame('2026-01-01', $holding->acquired_at->toDateString());
        $this->assertSame('active', $holding->status);
        $contribution = ShareContribution::query()->sole();
        $this->assertSame('123456.78', $contribution->amount);
        $this->assertSame($this->admin->id, $contribution->created_by);
        $this->assertSame($this->admin->id, ShareMovement::query()->sole()->created_by);
        $this->service->execute($done, $this->admin, true);
        $retry = $this->preview($rows, 'update');
        $this->assertSame(1, $retry->skipped_count);
        $this->service->execute($retry, $this->admin, true);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertDatabaseCount('share_contributions', 1);
        $conflict = $this->preview([[$owner->rut, '3', '123456.78', '2026-01-01', 'Saldo inicial']], 'update');
        $this->assertSame(1, $conflict->error_count);
    }

    public function test_unknown_amount_is_not_zero_and_can_later_be_reconciled(): void
    {
        $owner = $this->owner();
        $rows = [[$owner->rut, '5', '', '', '']];
        $this->service->execute($this->preview($rows), $this->admin, true);
        $this->assertDatabaseCount('share_contributions', 0);
        $this->assertNull(ShareHolding::query()->sole()->acquired_at);
        $this->assertTrue(app(ShareContributionService::class)->summary($owner)['has_unknown_history']);
        $this->assertSame(1, $this->preview($rows)->skipped_count);
        $known = $this->preview([[$owner->rut, '5', '0', '', '']]);
        $this->assertSame(0, $known->error_count);
        $this->service->execute($known, $this->admin, true);
        $this->assertSame('0.00', ShareContribution::query()->sole()->amount);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertFalse(app(ShareContributionService::class)->summary($owner->fresh())['has_unknown_history']);
        $this->assertSame(1, $this->preview($rows)->error_count);
    }

    public function test_conciles_existing_holdings_without_changing_dates_equivalence_or_status(): void
    {
        $owner = $this->owner();
        $holdings = app(ShareHoldingService::class);
        $holding = $holdings->initializeHistorical($owner, '3', '2026-01-02', 'legacy', 'Anterior', $this->admin);
        $holdings->adjust($holding, '3', 'inactive', 'deactivate', 'Inactiva', $this->admin);
        $before = $holding->fresh()->getAttributes();
        $this->assertSame(1, $this->preview([[$owner->rut, '4', '100', '', '']])->error_count);
        $done = $this->service->execute($this->preview([[$owner->rut, '3', '100', '', '']]), $this->admin, true);
        $this->assertSame('completed', $done->status);
        $this->assertSame($before, $holding->fresh()->getAttributes());
        $this->assertDatabaseCount('share_holdings', 1);
    }

    public function test_rejects_duplicate_ruts_bad_numbers_dates_and_unknown_owners(): void
    {
        $owner = $this->owner();
        $duplicate = $this->preview([[$owner->rut, '1', '', '', ''], [$owner->rut, '2', '', '', '']]);
        $this->assertSame(2, $duplicate->error_count);
        foreach ([
            [$owner->rut, '1,5', '', '', ''],
            [$owner->rut, '1.00001', '', '', ''],
            [$owner->rut, '1', '-1', '', ''],
            [$owner->rut, '1', '10.001', '', ''],
            [$owner->rut, '1', '100', today()->addDay()->toDateString(), ''],
            ['12.345.678-5', '1', '', '', ''],
        ] as $row) {
            $this->assertSame(1, $this->preview([$row])->error_count);
        }
        $this->assertDatabaseCount('share_holdings', 0);
    }

    public function test_precision_and_batch_capacity_are_checked_in_preview(): void
    {
        CommunitySetting::current()->update(['share_precision' => 0, 'authorized_shares' => '5']);
        $a = $this->owner();
        $b = $this->owner();
        $this->assertSame(1, $this->preview([[$a->rut, '0.5', '', '', '']])->error_count);
        $import = $this->preview([[$a->rut, '3', '100', '', ''], [$b->rut, '3', '200', '', '']]);
        $this->assertSame(1, $import->created_count);
        $this->assertSame(1, $import->error_count);
        $done = $this->service->execute($import, $this->admin, true);
        $this->assertSame('completed_with_errors', $done->status);
        $this->assertSame('3.0000', ShareHolding::query()->sole()->quantity);
    }

    public function test_changes_after_preview_are_rejected_and_capacity_is_rechecked(): void
    {
        $owner = $this->owner();
        $import = $this->preview([[$owner->rut, '3', '100', '', '']]);
        app(ShareHoldingService::class)->initializeHistorical($owner, '3', null, 'concurrent', 'Concurrente', $this->admin);
        $this->assertSame(1, $this->service->execute($import, $this->admin, true)->error_count);
        $this->assertDatabaseCount('share_contributions', 0);
        $other = $this->owner();
        $import = $this->preview([[$other->rut, '3', '100', '', '']]);
        CommunitySetting::current()->update(['authorized_shares' => '5']);
        $this->assertSame(1, $this->service->execute($import, $this->admin, true)->error_count);
        $this->assertDatabaseCount('share_holdings', 1);
    }

    public function test_row_rolls_back_holding_and_movement_if_contribution_fails(): void
    {
        $owner = $this->owner();
        $import = $this->preview([[$owner->rut, '3', '100', '', '']]);
        $this->mock(ShareContributionService::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('recordOpening')->once()->andReturnUsing(function (Propietario $owner): void {
                app(ShareHoldingService::class)->initializeHistorical($owner, '3', null, 'rollback', 'Rollback', $this->admin);
                throw ValidationException::withMessages(['amount' => 'Fallo de aporte']);
            });
        });
        $this->assertSame(1, $this->service->execute($import, $this->admin, true)->error_count);
        $this->assertDatabaseCount('share_holdings', 0);
        $this->assertDatabaseCount('share_movements', 0);
        $this->assertDatabaseCount('share_contributions', 0);
    }

    public function test_requires_explicit_confirmation(): void
    {
        $owner = $this->owner();
        $import = $this->preview([[$owner->rut, '1', '', '', '']]);
        $this->expectException(ValidationException::class);
        $this->service->execute($import, $this->admin, false);
    }

    public function test_reimport_does_not_falsely_confirm_a_changed_current_balance(): void
    {
        $owner = $this->owner();
        $rows = [[$owner->rut, '5', '100', '', '']];
        $this->service->execute($this->preview($rows), $this->admin, true);
        $retry = $this->preview($rows);
        $this->assertSame(1, $retry->skipped_count);
        $holding = $owner->shareHoldings()->sole();
        app(ShareHoldingService::class)->adjust($holding, '3', 'active', 'changed', 'Cambio posterior', $this->admin);
        $this->assertSame(1, $this->service->execute($retry, $this->admin, true)->error_count);
        $this->assertSame(1, $this->preview($rows)->error_count);
    }

    public function test_reimport_detects_corrections_to_the_original_contribution(): void
    {
        $owner = $this->owner();
        $rows = [[$owner->rut, '5', '100', '', '']];
        $this->service->execute($this->preview($rows), $this->admin, true);
        app(ShareContributionService::class)->correct(
            ShareContribution::query()->sole(), '200', 'Corrección posterior', 'corrected', $this->admin
        );
        $this->assertSame(1, $this->preview($rows)->error_count);
    }

    public function test_lots_mode_rejects_uploads(): void
    {
        CommunitySetting::current()->update(['mode' => 'lots']);
        $this->expectException(ValidationException::class);
        $this->preview([]);
    }

    public function test_non_admin_cannot_import(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('propietario');
        $this->expectException(HttpException::class);
        $this->service->start(UploadedFile::fake()->createWithContent('acciones.csv', "rut,quantity\n"), 'shares', $actor);
    }

    public function test_wizard_templates_history_and_owner_access(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = $this->owner();
        $page = Livewire::test(ImportShares::class)
            ->assertSee('saldo consolidado')
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-shares.csv')
            ->call('downloadTemplate', 'xlsx')->assertFileDownloaded('plantilla-shares.xlsx')
            ->set('file', UploadedFile::fake()->createWithContent('acciones.csv', "rut,quantity,amount,reference_date,notes\n{$owner->rut},2,100,,Inicial\n"))
            ->call('uploadFile')->assertHasNoErrors()
            ->call('preview')->assertHasNoErrors()->assertSet('step', 3)
            ->call('review')->assertSet('step', 4)
            ->call('execute')->assertHasErrors(['confirmed'])
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->call('downloadReport')->assertFileDownloaded('importacion-'.DataImport::query()->sole()->id.'.csv');
        $import = DataImport::query()->sole();
        $this->assertSame('completed', $import->status);
        $this->get(ImportShares::getUrl(['import' => $import->id]))->assertOk();
        Livewire::test(ImportHistory::class)->assertCanSeeTableRecords([$import])->assertSee('Acciones');

        $actor = User::factory()->create();
        $actor->assignRole('propietario');
        $this->actingAs($actor)->get(ImportShares::getUrl())->assertForbidden();
    }
}
