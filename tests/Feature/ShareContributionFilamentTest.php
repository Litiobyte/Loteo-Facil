<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Domain\Owners\Services\ShareContributionService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Resources\ShareContributionResource;
use App\Filament\Admin\Resources\ShareContributionResource\Pages\ListShareContributions;
use App\Filament\Admin\Resources\ShareHoldingResource\Pages\ManageShareHoldings;
use App\Filament\Resources\Propietarios\Pages\EditPropietario;
use App\Filament\Resources\Propietarios\RelationManagers\ShareContributionsRelationManager;
use App\Models\Propietario;
use App\Models\ShareContribution;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShareContributionFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares', 'square_meters_per_share' => '800', 'share_precision' => 4, 'authorized_shares' => null,
        ], 'Configuración', $this->admin);
    }

    public function test_historical_and_new_acquisition_forms_keep_amount_independent_of_quantity(): void
    {
        $owner = Propietario::factory()->create();
        Livewire::test(ManageShareHoldings::class)->callTableAction('opening', data: [
            'propietario_id' => $owner->id, 'quantity' => '50', 'amount' => '1.234.000',
            'operation_key' => 'ui-opening',
        ])->assertHasNoTableActionErrors();
        $opening = ShareContribution::query()->sole();
        $this->assertNull($opening->reference_date);
        $this->assertNull($opening->notes);
        $this->assertSame('1234000.00', $opening->amount);
        Livewire::test(ManageShareHoldings::class)->callTableAction('acquire', data: [
            'propietario_id' => $owner->id, 'quantity' => '1.5', 'amount' => '1.000.000',
            'acquired_at' => today()->toDateString(), 'operation_key' => 'ui-new',
        ])->assertHasNoTableActionErrors();
        $this->assertSame('2234000.00', app(ShareContributionService::class)->summary($owner)['recorded_amount']);
        $this->assertSame('51.5000', $owner->shareTotals()['quantity']);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_correction_and_owner_record_show_total_and_immutable_history(): void
    {
        $owner = Propietario::factory()->create();
        $opening = app(ShareContributionService::class)->recordOpening($owner, '50', '1000', null, null, 'opening', $this->admin);
        Livewire::test(ListShareContributions::class)->callTableAction('correct', $opening, [
            'corrected_amount' => '700', 'reason' => 'Corrección documentada', 'operation_key' => 'correct',
        ])->assertHasNoTableActionErrors();
        $this->assertSame('1000.00', $opening->fresh()->amount);
        $this->assertSame('700.00', app(ShareContributionService::class)->summary($owner)['recorded_amount']);
        Livewire::test(ShareContributionsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditPropietario::class])
            ->assertSee('700')->assertSee('50.0000')->assertSee('Corrección documentada')
            ->assertCanSeeTableRecords(ShareContribution::query()->get());
    }

    public function test_missing_amount_is_not_assumed_zero_and_normal_acquisition_requires_date(): void
    {
        $owner = Propietario::factory()->create();
        Livewire::test(ManageShareHoldings::class)->callTableAction('acquire', data: [
            'propietario_id' => $owner->id, 'quantity' => '10', 'amount' => null,
            'acquired_at' => null, 'operation_key' => 'invalid',
        ])->assertHasTableActionErrors(['amount', 'acquired_at']);
        $this->assertDatabaseCount('share_holdings', 0);
        $this->assertDatabaseCount('share_contributions', 0);
    }

    public function test_owners_cannot_access_administration_or_correction_actions(): void
    {
        $owner = Propietario::factory()->create();
        Role::findOrCreate('propietario', 'web');
        $owner->user->assignRole('propietario');
        $contribution = app(ShareContributionService::class)->recordOpening($owner, '1', '10', null, null, 'opening', $this->admin);
        $this->actingAs($owner->user);
        $this->assertFalse(ShareContributionResource::canAccess());
        $this->assertFalse(ShareContributionResource::canCreate());
        $this->assertFalse(ShareContributionResource::canEdit($contribution));
        Livewire::test(ListShareContributions::class)->assertForbidden();
    }

    public function test_acquisition_upload_stores_private_receipt_with_the_contribution(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = Propietario::factory()->create();
        Livewire::test(ManageShareHoldings::class)->callTableAction('acquire', data: [
            'propietario_id' => $owner->id, 'quantity' => '1', 'amount' => '25',
            'acquired_at' => today()->toDateString(), 'operation_key' => 'receipt',
            'receipt' => UploadedFile::fake()->image('comprobante.png'),
        ])->assertHasNoTableActionErrors();
        $contribution = ShareContribution::query()->sole();
        $this->assertStringStartsWith('share-contributions/', $contribution->receipt_path);
        Storage::disk('local')->assertExists($contribution->receipt_path);
        Storage::disk('public')->assertMissing($contribution->receipt_path);
    }

    public function test_receipt_is_removed_if_the_callers_transaction_rolls_back(): void
    {
        Storage::fake('local');
        $owner = Propietario::factory()->create();
        DB::beginTransaction();
        $contribution = app(ShareContributionService::class)->acquire(
            $owner, '1', '10', today()->toDateString(), null, 'outer', $this->admin, UploadedFile::fake()->image('comprobante.png')
        );
        Storage::disk('local')->assertExists($contribution->receipt_path);
        DB::rollBack();
        Storage::disk('local')->assertMissing($contribution->receipt_path);
        $this->assertDatabaseCount('share_holdings', 0);
        $this->assertDatabaseCount('share_contributions', 0);
    }

    public function test_started_economic_history_cannot_be_bypassed_with_legacy_acquisition(): void
    {
        $owner = Propietario::factory()->create();
        app(ShareContributionService::class)->acquire($owner, '1', '10', today()->toDateString(), null, 'first', $this->admin);
        try {
            app(ShareHoldingService::class)->acquire(
                $owner, '2', today()->toDateString(), 'legacy', 'Sin aporte', $this->admin
            );
            $this->fail('Debe utilizar el registro con aporte.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('share_holdings', 1);
        }
        Livewire::test(ManageShareHoldings::class)->callTableAction('acquire', data: [
            'propietario_id' => $owner->id, 'quantity' => '2', 'amount' => '15',
            'acquired_at' => today()->toDateString(), 'operation_key' => 'second',
        ])->assertHasNoTableActionErrors();
        $this->assertSame('25.00', app(ShareContributionService::class)->summary($owner)['recorded_amount']);
    }
}
