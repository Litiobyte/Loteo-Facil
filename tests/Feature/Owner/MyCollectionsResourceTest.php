<?php

namespace Tests\Feature\Owner;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Models\Collection;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MyCollectionsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
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
