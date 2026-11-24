<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareContributionService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareContribution;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShareContributionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ShareContributionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->service = app(ShareContributionService::class);

        $this->enableShareMode();
    }

    private function enableShareMode(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares',
            'square_meters_per_share' => '800',
            'share_precision' => 4,
            'authorized_shares' => null,
        ], 'Habilitar modalidad acciones', $this->admin);
    }

    public function test_migration_preserves_existing_holdings_and_does_not_invent_contributions(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Reejecución de migración con DDL sobre la conexión por defecto: solo SQLite.');
        }

        $owner = Propietario::factory()->create();
        $holding = app(ShareHoldingService::class)->acquire($owner, '50', '2026-01-01', 'legacy-migration', 'Dato anterior', $this->admin);
        $before = $holding->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_17_155033_create_share_contributions_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $holding->fresh()->getAttributes());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertDatabaseCount('share_movements', 1);
        $this->assertDatabaseCount('share_contributions', 0);
        $this->assertTrue($this->service->summary($owner)['has_unknown_history']);
        $this->service->recordOpening($owner, '50', '12345.67', null, null, 'migrated-opening', $this->admin);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertSame('12345.67', $this->service->summary($owner)['recorded_amount']);
    }

    public function test_rollback_does_not_invent_unknown_historical_dates_or_discard_history(): void
    {
        $owner = Propietario::factory()->create();
        $this->service->recordOpening($owner, '50', '100', null, null, 'unknown-date', $this->admin);
        $migration = require database_path('migrations/2026_11_17_155033_create_share_contributions_table.php');
        try {
            $migration->down();
            $this->fail('No debe inventar una fecha para volver a NOT NULL.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('share_contributions', 1);
            $this->assertNull($owner->shareHoldings()->sole()->acquired_at);
        }
    }

    public function test_migration_with_legacy_movements_commits_without_an_outer_transaction(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.share_migration_probe' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('share_migration_probe');
        try {
            $this->artisan('migrate', ['--database' => 'share_migration_probe', '--force' => true])->assertExitCode(0);
            $owner = Propietario::factory()->create();
            $admin = $owner->user;
            Role::findOrCreate('admin', 'web');
            $admin->assignRole('admin');
            app(CommunitySettingsService::class)->update([
                'mode' => 'shares', 'square_meters_per_share' => '800', 'share_precision' => 4, 'authorized_shares' => null,
            ], 'Prueba de migración real', $admin);
            $holding = app(ShareHoldingService::class)->acquire($owner, '50', '2026-01-01', 'root-migration', 'Dato anterior', $admin);
            $migration = require database_path('migrations/2026_11_17_155033_create_share_contributions_table.php');
            $this->assertSame(0, DB::transactionLevel());
            $migration->down();
            $migration->up();
            $this->assertSame('50.0000', $holding->fresh()->quantity);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            $this->assertDatabaseCount('share_movements', 1);
            $this->assertDatabaseCount('share_contributions', 0);
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge('share_migration_probe');
        }
    }

    public function test_record_opening_creates_contribution_and_historical_holding(): void
    {
        $owner = Propietario::factory()->create();

        $contribution = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            '2020-01-01',
            'Aporte inicial',
            'opening-'.$owner->id,
            $this->admin
        );

        $this->assertSame('opening', $contribution->type);
        $this->assertTrue($contribution->opening_marker);
        $this->assertSame('10.0000', $contribution->quantity);
        $this->assertSame('100000.00', $contribution->amount);
        $this->assertSame('2020-01-01', $contribution->reference_date->toDateString());
        $this->assertSame('Aporte inicial', $contribution->notes);
        $this->assertDatabaseCount('share_holdings', 1);

        $holding = $owner->shareHoldings()->sole();
        $this->assertSame('10.0000', $holding->quantity);
        $this->assertSame('active', $holding->status);

        $summary = $this->service->summary($owner);
        $this->assertSame('100000.00', $summary['recorded_amount']);
        $this->assertFalse($summary['has_unknown_history']);
    }

    public function test_record_opening_without_date_or_notes_works(): void
    {
        $owner = Propietario::factory()->create();

        $contribution = $this->service->recordOpening(
            $owner,
            '5',
            '50000.00',
            null,
            null,
            'opening-bare-'.$owner->id,
            $this->admin
        );

        $this->assertNull($contribution->reference_date);
        $this->assertNull($contribution->notes);
        $this->assertSame('5.0000', $contribution->quantity);
        $this->assertDatabaseCount('share_holdings', 1);
    }

    public function test_record_opening_reuses_existing_holdings_when_sum_matches(): void
    {
        $owner = Propietario::factory()->create();
        $legacy = ShareHolding::query()->create([
            'propietario_id' => $owner->id,
            'quantity' => '3.5000',
            'square_meters_per_share' => '800.0000',
            'acquired_at' => '2019-06-01',
            'status' => 'active',
            'notes' => 'Migración legacy',
        ]);
        ShareHolding::query()->create([
            'propietario_id' => $owner->id,
            'quantity' => '6.5000',
            'square_meters_per_share' => '800.0000',
            'acquired_at' => '2019-07-01',
            'status' => 'active',
            'notes' => 'Migración legacy 2',
        ]);

        $contribution = $this->service->recordOpening(
            $owner,
            '10',
            '95000.00',
            null,
            'Consolidado',
            'opening-reuse-'.$owner->id,
            $this->admin
        );

        $this->assertDatabaseCount('share_holdings', 2);
        $this->assertSame($legacy->id, $owner->shareHoldings()->orderBy('id')->first()->id);
        $this->assertNull($contribution->share_holding_id);
        $this->assertCount(2, $contribution->context['holdings']);

        $summary = $this->service->summary($owner);
        $this->assertSame('95000.00', $summary['recorded_amount']);
        $this->assertFalse($summary['has_unknown_history']);
    }

    public function test_record_opening_rolls_back_when_holding_quantity_mismatches(): void
    {
        $owner = Propietario::factory()->create();
        ShareHolding::query()->create([
            'propietario_id' => $owner->id,
            'quantity' => '7.0000',
            'square_meters_per_share' => '800.0000',
            'acquired_at' => '2019-06-01',
            'status' => 'active',
        ]);

        try {
            $this->service->recordOpening(
                $owner,
                '10',
                '70000.00',
                null,
                null,
                'opening-mismatch-'.$owner->id,
                $this->admin
            );
            $this->fail('Debió rechazar cantidad histórica distinta.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cantidad histórica no coincide', $exception->getMessage());
        }

        $this->assertDatabaseCount('share_contributions', 0);
        $this->assertDatabaseCount('share_holdings', 1);
        $this->assertSame('7.0000', $owner->shareHoldings()->sole()->quantity);
    }

    public function test_acquisition_without_opening_is_allowed_when_no_unknown_holdings(): void
    {
        $owner = Propietario::factory()->create();

        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-03-01',
            'Nueva adquisición',
            'acquire-clean-'.$owner->id,
            $this->admin
        );

        $this->assertSame('acquisition', $contribution->type);
        $this->assertSame('5.0000', $contribution->quantity);
        $this->assertSame('25000.00', $contribution->amount);
        $this->assertNotNull($contribution->share_holding_id);
        $this->assertDatabaseCount('share_holdings', 1);

        $summary = $this->service->summary($owner);
        $this->assertSame('25000.00', $summary['recorded_amount']);
        $this->assertFalse($summary['has_unknown_history']);
    }

    public function test_multiple_acquisitions_sum_independently(): void
    {
        $owner = Propietario::factory()->create();

        $first = $this->service->acquire(
            $owner,
            '2',
            '10000.00',
            '2026-01-01',
            null,
            'acquire-first-'.$owner->id,
            $this->admin
        );
        $second = $this->service->acquire(
            $owner,
            '3',
            '15000.50',
            '2026-02-01',
            null,
            'acquire-second-'.$owner->id,
            $this->admin
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('share_holdings', 2);
        $this->assertDatabaseCount('share_contributions', 2);

        $summary = $this->service->summary($owner);
        $this->assertTrue(BigDecimal::of($summary['recorded_amount'])->isEqualTo('25000.50'));
        $this->assertFalse($summary['has_unknown_history']);
    }

    public function test_acquisition_is_blocked_when_unknown_history_exists(): void
    {
        $owner = Propietario::factory()->create();
        ShareHolding::query()->create([
            'propietario_id' => $owner->id,
            'quantity' => '5.0000',
            'square_meters_per_share' => '800.0000',
            'acquired_at' => '2018-01-01',
            'status' => 'active',
            'notes' => 'Legacy',
        ]);

        $summary = $this->service->summary($owner);
        $this->assertTrue($summary['has_unknown_history']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('saldo histórico');

        $this->service->acquire(
            $owner,
            '1',
            '5000.00',
            '2026-01-01',
            null,
            'acquire-blocked-'.$owner->id,
            $this->admin
        );
    }

    public function test_opening_is_blocked_after_existing_contributions(): void
    {
        $owner = Propietario::factory()->create();
        $this->service->acquire(
            $owner,
            '1',
            '1000.00',
            '2026-01-01',
            null,
            'acquire-first-'.$owner->id,
            $this->admin
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('saldo inicial');

        $this->service->recordOpening(
            $owner,
            '1',
            '1000.00',
            null,
            null,
            'opening-late-'.$owner->id,
            $this->admin
        );
    }

    public function test_legacy_holdings_without_contribution_remain_unknown_and_are_not_backfilled(): void
    {
        $owner = Propietario::factory()->create();
        ShareHolding::query()->create([
            'propietario_id' => $owner->id,
            'quantity' => '8.0000',
            'square_meters_per_share' => '800.0000',
            'acquired_at' => '2017-01-01',
            'status' => 'active',
        ]);

        $summary = $this->service->summary($owner);
        $this->assertTrue($summary['has_unknown_history']);
        $this->assertSame('0.00', $summary['recorded_amount']);
        $this->assertDatabaseCount('share_contributions', 0);
    }

    public function test_amount_is_never_derived_from_quantity(): void
    {
        $owner = Propietario::factory()->create();

        $opening = $this->service->recordOpening(
            $owner,
            '10',
            '123.45',
            null,
            null,
            'opening-amount-'.$owner->id,
            $this->admin
        );

        $this->assertSame('123.45', $opening->amount);

        $acquisition = $this->service->acquire(
            $owner,
            '5',
            '99999.99',
            '2026-01-01',
            null,
            'acquire-amount-'.$owner->id,
            $this->admin
        );

        $this->assertSame('99999.99', $acquisition->amount);
    }

    public function test_correction_creates_immutable_delta_without_changing_quantity(): void
    {
        $owner = Propietario::factory()->create();
        $original = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'opening-correct-'.$owner->id,
            $this->admin
        );

        $correction = $this->service->correct(
            $original,
            '110000.00',
            'Ajuste por auditoría',
            'correction-'.$owner->id,
            $this->admin
        );

        $this->assertSame('correction', $correction->type);
        $this->assertSame($original->id, $correction->corrects_contribution_id);
        $this->assertSame('10000.00', $correction->amount);
        $this->assertNull($correction->quantity);
        $this->assertSame('110000.00', $correction->context['amount_after']);
        $this->assertSame('100000.00', $correction->context['amount_before']);

        $original->refresh();
        $this->assertSame('10.0000', $original->quantity);
        $this->assertSame('100000.00', $original->amount);

        $summary = $this->service->summary($owner);
        $this->assertSame('110000.00', $summary['recorded_amount']);
    }

    public function test_correction_can_reduce_amount(): void
    {
        $owner = Propietario::factory()->create();
        $original = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'opening-reduce-'.$owner->id,
            $this->admin
        );

        $correction = $this->service->correct(
            $original,
            '90000.00',
            'Corrección menor',
            'correction-reduce-'.$owner->id,
            $this->admin
        );

        $this->assertSame('-10000.00', $correction->amount);
        $this->assertSame('90000.00', $this->service->summary($owner)['recorded_amount']);
    }

    public function test_correction_of_correction_is_rejected(): void
    {
        $owner = Propietario::factory()->create();
        $original = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'opening-corrchain-'.$owner->id,
            $this->admin
        );
        $correction = $this->service->correct(
            $original,
            '110000.00',
            'Primera corrección',
            'correction-chain-'.$owner->id,
            $this->admin
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('corrección debe referir al aporte original');

        $this->service->correct(
            $correction,
            '120000.00',
            'Corrección sobre corrección',
            'correction-of-correction-'.$owner->id,
            $this->admin
        );
    }

    public function test_idempotency_returns_same_record_for_identical_key_and_payload(): void
    {
        $owner = Propietario::factory()->create();

        $first = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            '2020-01-01',
            'Notas',
            'idempotent-key-'.$owner->id,
            $this->admin
        );
        $second = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            '2020-01-01',
            'Notas',
            'idempotent-key-'.$owner->id,
            $this->admin
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('share_contributions', 1);
        $this->assertDatabaseCount('share_holdings', 1);
    }

    public function test_idempotency_is_independent_of_request_key_order(): void
    {
        $owner = Propietario::factory()->create();
        $key = 'idempotent-order-'.$owner->id;

        $first = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            'Notas',
            $key,
            $this->admin
        );

        $contribution = ShareContribution::query()->findOrFail($first->id);
        $context = $contribution->context;
        $context['request'] = array_reverse($context['request'], true);
        DB::table('share_contributions')->where('id', $first->id)->update([
            'context' => json_encode($context),
        ]);

        $second = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            'Notas',
            $key,
            $this->admin
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('share_contributions', 1);
    }

    public function test_idempotency_rejects_mismatched_payload(): void
    {
        $owner = Propietario::factory()->create();
        $key = 'idempotent-mismatch-'.$owner->id;

        $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            $key,
            $this->admin
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('clave ya fue utilizada');

        $this->service->recordOpening(
            $owner,
            '10',
            '100001.00',
            null,
            null,
            $key,
            $this->admin
        );
    }

    public function test_idempotency_rejects_same_key_for_different_owner_or_type(): void
    {
        $ownerA = Propietario::factory()->create();
        $ownerB = Propietario::factory()->create();
        $key = 'shared-key-'.uniqid();

        $this->service->recordOpening(
            $ownerA,
            '10',
            '100000.00',
            null,
            null,
            $key,
            $this->admin
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('clave ya fue utilizada');

        $this->service->recordOpening(
            $ownerB,
            '10',
            '100000.00',
            null,
            null,
            $key,
            $this->admin
        );
    }

    public function test_transfer_does_not_transfer_contribution_history(): void
    {
        $source = Propietario::factory()->create();
        $recipient = Propietario::factory()->create();

        $this->service->recordOpening(
            $source,
            '10',
            '100000.00',
            null,
            null,
            'opening-source-'.$source->id,
            $this->admin
        );

        $holding = $source->shareHoldings()->sole();
        app(ShareHoldingService::class)->transfer(
            $holding,
            $recipient,
            '4',
            'transfer-'.$source->id,
            'Cesión',
            $this->admin
        );

        $this->assertSame('6.0000', $source->shareHoldings()->sole()->quantity);
        $this->assertSame('100000.00', $this->service->summary($source)['recorded_amount']);
        $this->assertTrue($this->service->summary($source)['has_unknown_history'] === false);

        $recipientSummary = $this->service->summary($recipient);
        $this->assertSame('0.00', $recipientSummary['recorded_amount']);
        // La titularidad transferida se rastrea por movimiento; el aporte monetario no se transfiere.
        $this->assertFalse($recipientSummary['has_unknown_history']);
    }

    public function test_non_admin_is_blocked_from_service_methods(): void
    {
        $owner = Propietario::factory()->create();
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');
        $owner->update(['user_id' => $ownerUser->id]);

        $this->expectException(HttpException::class);

        $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'forbidden-opening',
            $ownerUser
        );
    }

    public function test_acquisition_links_holding_and_movement_to_contribution_key(): void
    {
        $owner = Propietario::factory()->create();
        $key = 'acquire-linked-'.$owner->id;

        $contribution = $this->service->acquire(
            $owner,
            '10',
            '50000.00',
            '2026-01-01',
            null,
            $key,
            $this->admin
        );

        $movement = ShareMovement::query()->where('type', 'acquire')->sole();
        $this->assertStringContainsString('contribution:', $movement->operation_key);
        $this->assertSame($contribution->share_holding_id, $movement->share_holding_id);
        $this->assertSame($owner->shareHoldings()->sole()->id, $contribution->share_holding_id);
    }

    public function test_invalid_capacity_rolls_back_contribution_and_holding(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares',
            'square_meters_per_share' => '800',
            'share_precision' => 0,
            'authorized_shares' => '5',
        ], 'Capacidad reducida', $this->admin);

        $owner = Propietario::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede el total autorizado');

        $this->service->acquire(
            $owner,
            '10',
            '50000.00',
            '2026-01-01',
            null,
            'capacity-fail',
            $this->admin
        );

        $this->assertDatabaseCount('share_contributions', 0);
        $this->assertDatabaseCount('share_holdings', 0);
    }

    public function test_receipt_is_stored_and_downloaded_by_admin(): void
    {
        Storage::fake('local');

        $owner = Propietario::factory()->create();
        $receipt = UploadedFile::fake()->image('receipt.jpg');

        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'acquire-receipt-'.$owner->id,
            $this->admin,
            $receipt
        );

        $this->assertNotNull($contribution->receipt_path);
        Storage::disk('local')->assertExists($contribution->receipt_path);
        $this->assertStringStartsWith('share-contributions/', $contribution->receipt_path);
    }

    public function test_receipt_storage_failure_rolls_back_contribution_and_cleans_file(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->with('share-contributions', Mockery::type(UploadedFile::class), Mockery::type('string'), Mockery::any())
            ->andReturn('share-contributions/fake.pdf');
        $disk->shouldReceive('exists')
            ->with('share-contributions/fake.pdf')
            ->andReturn(false);
        $disk->shouldReceive('delete')
            ->with('share-contributions/fake.pdf')
            ->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $owner = Propietario::factory()->create();
        $receipt = UploadedFile::fake()->image('receipt.jpg');

        try {
            $this->service->acquire(
                $owner,
                '5',
                '25000.00',
                '2026-01-01',
                null,
                'receipt-fail-'.$owner->id,
                $this->admin,
                $receipt
            );
            $this->fail('Debió fallar al guardar comprobante.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('comprobante', $exception->getMessage());
        }

        $this->assertDatabaseCount('share_contributions', 0);
        $this->assertDatabaseCount('share_holdings', 0);
    }

    public function test_receipt_mime_validation_rejects_invalid_files(): void
    {
        $owner = Propietario::factory()->create();
        $invalid = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $this->expectException(ValidationException::class);

        $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'receipt-mime-'.$owner->id,
            $this->admin,
            $invalid
        );
    }

    public function test_receipt_is_optional(): void
    {
        $owner = Propietario::factory()->create();

        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'acquire-no-receipt-'.$owner->id,
            $this->admin
        );

        $this->assertNull($contribution->receipt_path);
    }

    public function test_admin_can_download_receipt(): void
    {
        Storage::fake('local');

        $owner = Propietario::factory()->create();
        $receipt = UploadedFile::fake()->image('receipt.jpg');

        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'download-admin-'.$owner->id,
            $this->admin,
            $receipt
        );

        $response = $this->service->downloadReceipt($contribution, $this->admin);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('comprobante-aporte-'.$contribution->id, $response->headers->get('Content-Disposition'));
    }

    public function test_owner_can_download_own_receipt(): void
    {
        Storage::fake('local');

        $owner = Propietario::factory()->create();
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');
        $owner->update(['user_id' => $ownerUser->id]);

        $receipt = UploadedFile::fake()->image('receipt.jpg');
        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'download-owner-'.$owner->id,
            $this->admin,
            $receipt
        );

        $response = $this->service->downloadReceipt($contribution, $ownerUser);

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_other_owner_cannot_download_receipt(): void
    {
        Storage::fake('local');

        $owner = Propietario::factory()->create();
        $otherOwner = Propietario::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->assignRole('propietario');
        $otherOwner->update(['user_id' => $otherUser->id]);

        $receipt = UploadedFile::fake()->image('receipt.jpg');
        $contribution = $this->service->acquire(
            $owner,
            '5',
            '25000.00',
            '2026-01-01',
            null,
            'download-other-'.$owner->id,
            $this->admin,
            $receipt
        );

        $this->expectException(HttpException::class);
        $this->service->downloadReceipt($contribution, $otherUser);
    }

    public function test_share_mode_is_required_for_new_registrations(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'lots',
            'square_meters_per_share' => '800',
            'share_precision' => 0,
            'authorized_shares' => null,
        ], 'Deshabilitar acciones', $this->admin);

        $owner = Propietario::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modalidad no admite');

        $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'opening-lots-mode',
            $this->admin
        );
    }

    public function test_contributions_do_not_create_charges(): void
    {
        $owner = Propietario::factory()->create();

        $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'opening-no-charges-'.$owner->id,
            $this->admin
        );
        $this->service->acquire(
            $owner,
            '5',
            '50000.00',
            '2026-01-01',
            null,
            'acquire-no-charges-'.$owner->id,
            $this->admin
        );

        $this->assertDatabaseCount('partner_charges', 0);
        $this->assertSame(0, PartnerCharge::query()->where('propietario_id', $owner->id)->count());
    }

    public function test_contribution_records_are_immutable(): void
    {
        $owner = Propietario::factory()->create();
        $contribution = $this->service->recordOpening(
            $owner,
            '10',
            '100000.00',
            null,
            null,
            'immutable-'.$owner->id,
            $this->admin
        );

        $this->expectException(DomainException::class);
        $contribution->update(['amount' => '1.00']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
