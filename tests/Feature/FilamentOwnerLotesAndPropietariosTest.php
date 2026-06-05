<?php

namespace Tests\Feature;

use App\Filament\Owner\Resources\Lotes\LoteResource as OwnerLoteResource;
use App\Filament\Resources\Propietarios\RelationManagers\LotesRelationManager;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\Comuna;
use App\Models\Etapa;
use App\Models\Region;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentOwnerLotesAndPropietariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $region = Region::query()->create(['nombre' => 'Region Test']);
        Comuna::query()->create([
            'region_id' => $region->id,
            'nombre' => 'Comuna Test',
        ]);
        Etapa::query()->create([
            'numero' => 0,
            'nombre' => 'Etapa 0',
        ]);
    }

    public function test_owner_only_sees_his_own_active_lotes_in_mis_lotes_resource_scope(): void
    {
        $ownerAUser = User::factory()->create();
        $ownerAUser->assignRole('propietario');
        $ownerA = Propietario::factory()->create(['user_id' => $ownerAUser->id]);

        $ownerBUser = User::factory()->create();
        $ownerBUser->assignRole('propietario');
        $ownerB = Propietario::factory()->create(['user_id' => $ownerBUser->id]);

        $loteA = Lote::factory()->create(['codigo' => 'LT-A-001']);
        $loteB = Lote::factory()->create(['codigo' => 'LT-B-001']);
        $loteAOld = Lote::factory()->create(['codigo' => 'LT-A-OLD']);

        $ownerA->lotes()->attach($loteA->id, [
            'assigned_at' => now()->subDays(5),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $ownerA->lotes()->attach($loteAOld->id, [
            'assigned_at' => now()->subDays(20),
            'unassigned_at' => now()->subDays(2),
            'status' => 'unassigned',
        ]);

        $ownerB->lotes()->attach($loteB->id, [
            'assigned_at' => now()->subDays(3),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $this->actingAs($ownerAUser);

        $visibleCodes = OwnerLoteResource::getEloquentQuery()
            ->pluck('codigo')
            ->all();

        $this->assertSame(['LT-A-001'], $visibleCodes);
        $this->assertNotContains('LT-B-001', $visibleCodes);
        $this->assertNotContains('LT-A-OLD', $visibleCodes);
    }

    public function test_propietario_resource_eligible_users_query_only_returns_propietario_users_without_profile(): void
    {
        $eligibleUser = User::factory()->create();
        $eligibleUser->assignRole('propietario');

        $alreadyLinkedUser = User::factory()->create();
        $alreadyLinkedUser->assignRole('propietario');
        Propietario::factory()->create(['user_id' => $alreadyLinkedUser->id]);

        $adminUser = User::factory()->create();
        $adminUser->assignRole('admin');

        $eligibleIds = PropietarioResource::eligibleUsersQuery(User::query())
            ->pluck('id')
            ->all();

        $this->assertSame([$eligibleUser->id], $eligibleIds);
        $this->assertNotContains($alreadyLinkedUser->id, $eligibleIds);
        $this->assertNotContains($adminUser->id, $eligibleIds);
    }

    public function test_propietario_resource_eligible_users_for_form_query_keeps_current_edit_user_available(): void
    {
        $currentUser = User::factory()->create();
        $currentUser->assignRole('propietario');
        $record = Propietario::factory()->create(['user_id' => $currentUser->id]);

        $otherEligibleUser = User::factory()->create();
        $otherEligibleUser->assignRole('propietario');

        $alreadyLinkedDifferentUser = User::factory()->create();
        $alreadyLinkedDifferentUser->assignRole('propietario');
        Propietario::factory()->create(['user_id' => $alreadyLinkedDifferentUser->id]);

        $eligibleIds = PropietarioResource::eligibleUsersForFormQuery(User::query(), $record)
            ->pluck('id')
            ->all();

        $this->assertContains($currentUser->id, $eligibleIds);
        $this->assertContains($otherEligibleUser->id, $eligibleIds);
        $this->assertNotContains($alreadyLinkedDifferentUser->id, $eligibleIds);
    }

    public function test_assignment_ui_only_lists_lotes_without_global_active_assignment(): void
    {
        $ownerAUser = User::factory()->create();
        $ownerAUser->assignRole('propietario');
        $ownerA = Propietario::factory()->create(['user_id' => $ownerAUser->id]);

        $loteActive = Lote::factory()->create(['codigo' => 'LT-ACTIVE', 'estado' => 'disponible']);
        $loteAvailable = Lote::factory()->create(['codigo' => 'LT-FREE', 'estado' => 'disponible']);
        $loteReservado = Lote::factory()->create(['codigo' => 'LT-RES', 'estado' => 'reservado']);

        $ownerA->lotes()->attach($loteActive->id, [
            'assigned_at' => now()->subDay(),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $ownerA->lotes()->attach($loteAvailable->id, [
            'assigned_at' => now()->subDays(5),
            'unassigned_at' => now()->subDays(2),
            'status' => 'unassigned',
        ]);

        $options = LotesRelationManager::assignableLoteOptions();

        $this->assertArrayNotHasKey($loteActive->id, $options);
        $this->assertSame('LT-FREE', $options[$loteAvailable->id] ?? null);
        $this->assertArrayNotHasKey($loteReservado->id, $options);
    }

    public function test_propietario_lotes_relation_manager_uses_only_active_assignments_relationship(): void
    {
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $ownerUser->id]);

        $activeLote = Lote::factory()->create(['codigo' => 'LT-ACTIVE-RM']);
        $unassignedLote = Lote::factory()->create(['codigo' => 'LT-UNASSIGNED-RM']);

        $owner->lotes()->attach($activeLote->id, [
            'assigned_at' => now()->subDays(3),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $owner->lotes()->attach($unassignedLote->id, [
            'assigned_at' => now()->subDays(6),
            'unassigned_at' => now()->subDay(),
            'status' => 'unassigned',
        ]);

        $this->assertSame('lotesActivos', LotesRelationManager::getRelationshipName());
        $this->assertSame(['LT-ACTIVE-RM'], $owner->lotesActivos()->pluck('codigo')->all());
    }

    public function test_propietario_resource_index_aggregates_assigned_lotes_and_hectareas_only_for_active_reserved_or_vendido(): void
    {
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $ownerUser->id]);

        $reservedActive = Lote::factory()->create([
            'estado' => 'reservado',
            'metros_cuadrados' => 15000,
        ]);
        $soldActive = Lote::factory()->create([
            'estado' => 'vendido',
            'metros_cuadrados' => 5000,
        ]);
        $availableActive = Lote::factory()->create([
            'estado' => 'disponible',
            'metros_cuadrados' => 12000,
        ]);
        $reservedUnassigned = Lote::factory()->create([
            'estado' => 'reservado',
            'metros_cuadrados' => 9000,
        ]);

        $owner->lotes()->attach($reservedActive->id, [
            'assigned_at' => now()->subDays(5),
            'unassigned_at' => null,
            'status' => 'active',
        ]);
        $owner->lotes()->attach($soldActive->id, [
            'assigned_at' => now()->subDays(4),
            'unassigned_at' => null,
            'status' => 'active',
        ]);
        $owner->lotes()->attach($availableActive->id, [
            'assigned_at' => now()->subDays(3),
            'unassigned_at' => null,
            'status' => 'active',
        ]);
        $owner->lotes()->attach($reservedUnassigned->id, [
            'assigned_at' => now()->subDays(10),
            'unassigned_at' => now()->subDay(),
            'status' => 'unassigned',
        ]);

        $record = PropietarioResource::getEloquentQuery()
            ->whereKey($owner->id)
            ->firstOrFail();

        $this->assertSame(2, (int) $record->lotes_asignados_count);
        $this->assertSame(20000.0, (float) $record->lotes_activos_m2_sum);
    }
}
