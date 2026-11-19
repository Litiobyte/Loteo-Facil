<?php

namespace Tests\Feature\Owner;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Filament\Owner\Resources\Collections\Pages\ListMyCollections;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MyCollectionsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_redesigned_payments_show_real_owner_totals_and_filtered_history(): void
    {
        [$user, $owner] = $this->createOwner();
        $partial = Collection::factory()->state(['amount' => 10000])->partiallyApplied()->create(['propietario_id' => $owner->id, 'reference' => 'MI-ABONO', 'collection_method' => CollectionMethod::Transferencia]);
        $full = Collection::factory()->state(['amount' => 5000])->fullyApplied()->create(['propietario_id' => $owner->id, 'reference' => 'MI-PAGO']);
        $pending = Collection::factory()->create(['propietario_id' => $owner->id, 'amount' => 2000]);
        $cancelled = Collection::factory()->cancelled()->create(['propietario_id' => $owner->id, 'amount' => 999999]);
        $other = Collection::factory()->create(['reference' => 'REFERENCIA-PRIVADA']);
        $this->actingAs($user);

        $this->get(MyCollectionsResource::getUrl())->assertOk()
            ->assertSee('Historial de pagos')->assertSee('MI-ABONO')
            ->assertSee('¿Qué significa «aplicado»?')->assertDontSee('REFERENCIA-PRIVADA');

        Livewire::test(ListMyCollections::class)
            ->assertViewHas('paidTotal', 17000.0)
            ->assertViewHas('appliedTotal', 9000.0)
            ->assertViewHas('availableTotal', 8000.0)
            ->assertViewHas('paymentCount', 4)
            ->assertCanSeeTableRecords([$partial, $full, $pending, $cancelled])
            ->assertCanNotSeeTableRecords([$other])
            ->set('activeTab', 'applied')
            ->assertCanSeeTableRecords([$partial, $full])
            ->assertCanNotSeeTableRecords([$pending, $cancelled])
            ->set('activeTab', 'available')
            ->assertCanSeeTableRecords([$partial, $pending])
            ->assertCanNotSeeTableRecords([$full, $cancelled])
            ->searchTable('MI-ABONO')
            ->assertCanSeeTableRecords([$partial])->assertCanNotSeeTableRecords([$pending])
            ->searchTable('Transferencia')->assertCanSeeTableRecords([$partial]);
    }

    public function test_payment_modal_and_direct_detail_show_real_allocations_and_are_scoped(): void
    {
        [$user, $owner] = $this->createOwner();
        $payment = Collection::factory()->create(['propietario_id' => $owner->id, 'amount' => 10000, 'notes' => 'Nota real del pago']);
        $charge = PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'amount' => 10000, 'description' => 'Cobro de mantención real']);
        CollectionAllocation::factory()->create(['collection_id' => $payment->id, 'partner_charge_id' => $charge->id, 'amount' => 4000]);
        $payment->update(['applied_amount' => 4000, 'status' => CollectionStatus::PartiallyApplied]);
        $other = Collection::factory()->create(['notes' => 'NOTA-PRIVADA']);
        $this->actingAs($user);

        $page = Livewire::test(ListMyCollections::class)
            ->mountTableAction('detail', $payment)
            ->assertSet('mountedActions.0.name', 'detail')
            ->assertSet('mountedActions.0.context.recordKey', (string) $payment->id);
        $modal = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Nota real del pago', $modal);
        $this->assertStringContainsString('Cobro de mantención real', $modal);
        $this->assertStringContainsString('Aplicación del pago', $modal);
        $this->assertStringNotContainsString('NOTA-PRIVADA', $modal);
        Livewire::test(ListMyCollections::class)
            ->mountTableAction('detail', $other)->assertDontSee('NOTA-PRIVADA');
        $this->get(MyCollectionsResource::getUrl('view', ['record' => $payment]))
            ->assertOk()->assertSee('Nota real del pago')->assertSee('Cobro de mantención real');
        $this->get(MyCollectionsResource::getUrl('view', ['record' => $other]))->assertNotFound();
    }

    public function test_empty_history_and_payment_without_applications_are_explicit(): void
    {
        [$user, $owner] = $this->createOwner();
        $this->actingAs($user);
        Livewire::test(ListMyCollections::class)->assertSee('Sin pagos registrados')
            ->assertViewHas('paidTotal', 0.0)->assertViewHas('appliedTotal', 0.0)->assertViewHas('availableTotal', 0.0);
        $payment = Collection::factory()->create(['propietario_id' => $owner->id]);
        $page = Livewire::test(ListMyCollections::class)->mountTableAction('detail', $payment)
            ->assertSet('mountedActions.0.name', 'detail');
        $this->assertStringContainsString('No tiene aplicaciones registradas.', $page->instance()->getMountedAction()->getModalContent()->render());
    }

    public function test_csv_is_scoped_filtered_and_neutralizes_spreadsheet_formulas(): void
    {
        [$user, $owner] = $this->createOwner();
        Collection::factory()->state(['amount' => 10000])->partiallyApplied()->create(['propietario_id' => $owner->id, 'reference' => '=MI-FORMULA']);
        Collection::factory()->fullyApplied()->create(['propietario_id' => $owner->id, 'reference' => 'OTRO-PAGO']);
        Collection::factory()->create(['reference' => 'REFERENCIA-PRIVADA']);
        $this->actingAs($user);
        $page = Livewire::test(ListMyCollections::class)->set('activeTab', 'available')->searchTable('MI-FORMULA');
        $csv = TestResponse::fromBaseResponse($page->instance()->exportCsv())->streamedContent();
        $this->assertStringContainsString("'=MI-FORMULA", $csv);
        $this->assertStringContainsString('10000', $csv);
        $this->assertStringNotContainsString('OTRO-PAGO', $csv);
        $this->assertStringNotContainsString('REFERENCIA-PRIVADA', $csv);
        $page->call('exportCsv')->assertFileDownloaded('mis-pagos.csv');
    }

    public function test_scope_only_returns_authenticated_owner_collections(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        Collection::factory()->count(2)->create(['propietario_id' => $owner->id]);
        Collection::factory()->count(3)->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertSame(2, MyCollectionsResource::getEloquentQuery()->count());
    }

    public function test_scope_returns_empty_query_for_user_without_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $this->assertSame(0, MyCollectionsResource::getEloquentQuery()->count());
    }

    public function test_resource_is_read_only_for_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        $payment = Collection::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $this->assertFalse(MyCollectionsResource::canCreate());
        $this->assertFalse(MyCollectionsResource::canEdit($payment));
        $this->assertFalse(MyCollectionsResource::canDelete($payment));
    }

    public function test_can_view_only_own_payment(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        $own = Collection::factory()->create(['propietario_id' => $owner->id]);
        $other = Collection::factory()->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertTrue(MyCollectionsResource::canView($own));
        $this->assertFalse(MyCollectionsResource::canView($other));
    }

    public function test_collection_method_label_mapping_is_correct(): void
    {
        $this->assertSame('Efectivo', MyCollectionsResource::paymentMethodLabel(CollectionMethod::Efectivo));
        $this->assertSame('Transferencia', MyCollectionsResource::paymentMethodLabel(CollectionMethod::Transferencia));
        $this->assertSame('Cheque', MyCollectionsResource::paymentMethodLabel(CollectionMethod::Cheque));
        $this->assertSame('Otro', MyCollectionsResource::paymentMethodLabel(CollectionMethod::Otro));
    }

    public function test_status_label_mapping_is_correct(): void
    {
        $this->assertSame('Pendiente aplicación', MyCollectionsResource::statusLabel(CollectionStatus::PendingApplication));
        $this->assertSame('Parcialmente aplicado', MyCollectionsResource::statusLabel(CollectionStatus::PartiallyApplied));
        $this->assertSame('Totalmente aplicado', MyCollectionsResource::statusLabel(CollectionStatus::FullyApplied));
        $this->assertSame('Cancelado', MyCollectionsResource::statusLabel(CollectionStatus::Cancelled));
    }

    public function test_status_color_mapping_is_correct(): void
    {
        $this->assertSame('gray', MyCollectionsResource::statusColor(CollectionStatus::PendingApplication));
        $this->assertSame('warning', MyCollectionsResource::statusColor(CollectionStatus::PartiallyApplied));
        $this->assertSame('success', MyCollectionsResource::statusColor(CollectionStatus::FullyApplied));
        $this->assertSame('danger', MyCollectionsResource::statusColor(CollectionStatus::Cancelled));
    }

    public function test_quick_filter_conditions_last_month_and_year_can_be_applied(): void
    {
        [$user, $owner] = $this->createOwner();

        Collection::factory()->create(['propietario_id' => $owner->id, 'collection_date' => now()->subDays(15)->toDateString()]);
        Collection::factory()->create(['propietario_id' => $owner->id, 'collection_date' => now()->subYears(2)->toDateString()]);

        $this->actingAs($user);

        $lastMonth = MyCollectionsResource::getEloquentQuery()
            ->whereDate('collection_date', '>=', now()->subMonthNoOverflow()->startOfDay()->toDateString())
            ->count();
        $thisYear = MyCollectionsResource::getEloquentQuery()
            ->whereYear('collection_date', now()->year)
            ->count();

        $this->assertSame(1, $lastMonth);
        $this->assertSame(1, $thisYear);
    }

    /**
     * @return array{0: User, 1: Propietario}
     */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $user->id]);

        return [$user, $owner];
    }
}
