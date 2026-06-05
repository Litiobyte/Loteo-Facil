<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\LoteOwnershipAssignmentService;
use App\Domain\Owners\Services\PropietarioRegistrationService;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_propietario_registration_requires_propietario_role(): void
    {
        $this->expectException(DomainException::class);

        $service = app(PropietarioRegistrationService::class);
        $user = User::factory()->create();

        $service->register($user);
    }

    public function test_propietario_registration_is_idempotent_for_same_user(): void
    {
        $service = app(PropietarioRegistrationService::class);
        $user = User::factory()->create();
        $user->assignRole('propietario');

        $first = $service->register($user);
        $second = $service->register($user);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('propietarios', 1);
    }

    public function test_propietarios_table_enforces_unique_user_relationship(): void
    {
        $this->expectException(QueryException::class);

        $user = User::factory()->create();

        Propietario::factory()->create(['user_id' => $user->id]);
        Propietario::factory()->create(['user_id' => $user->id]);
    }

    public function test_hectareas_totales_activas_sums_only_active_assignments(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        $propietario = Propietario::factory()->create(['user_id' => $user->id]);

        $activeLote = Lote::factory()->create(['metros_cuadrados' => 125000]);
        $inactiveLote = Lote::factory()->create(['metros_cuadrados' => 52500]);
        $unassignedLote = Lote::factory()->create(['metros_cuadrados' => 27500]);

        $propietario->lotes()->attach($activeLote->id, [
            'assigned_at' => now()->subDays(10),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $propietario->lotes()->attach($inactiveLote->id, [
            'assigned_at' => now()->subDays(20),
            'unassigned_at' => now()->subDay(),
            'status' => 'inactive',
        ]);

        $propietario->lotes()->attach($unassignedLote->id, [
            'assigned_at' => now()->subDays(30),
            'unassigned_at' => now(),
            'status' => 'unassigned',
        ]);

        $this->assertSame(12.5, $propietario->hectareasTotalesActivas());
    }

    public function test_hectareas_totales_activas_is_isolated_per_propietario(): void
    {
        $ownerAUser = User::factory()->create();
        $ownerAUser->assignRole('propietario');
        $ownerA = Propietario::factory()->create(['user_id' => $ownerAUser->id]);

        $ownerBUser = User::factory()->create();
        $ownerBUser->assignRole('propietario');
        $ownerB = Propietario::factory()->create(['user_id' => $ownerBUser->id]);

        $ownerALote = Lote::factory()->create(['metros_cuadrados' => 200000]);
        $ownerBLote = Lote::factory()->create(['metros_cuadrados' => 400000]);

        $ownerA->lotes()->attach($ownerALote->id, [
            'assigned_at' => now(),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $ownerB->lotes()->attach($ownerBLote->id, [
            'assigned_at' => now(),
            'unassigned_at' => null,
            'status' => 'active',
        ]);

        $this->assertSame(20.0, $ownerA->hectareasTotalesActivas());
        $this->assertSame(40.0, $ownerB->hectareasTotalesActivas());
    }

    public function test_cannot_assign_active_lote_to_another_propietario(): void
    {
        $ownerAUser = User::factory()->create();
        $ownerAUser->assignRole('propietario');
        $ownerA = Propietario::factory()->create(['user_id' => $ownerAUser->id]);

        $ownerBUser = User::factory()->create();
        $ownerBUser->assignRole('propietario');
        $ownerB = Propietario::factory()->create(['user_id' => $ownerBUser->id]);

        $lote = Lote::factory()->create(['estado' => 'disponible']);

        $service = app(LoteOwnershipAssignmentService::class);
        $service->assign($ownerA, $lote);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El lote ya tiene una asignacion activa.');

        $service->assign($ownerB, $lote);
    }

    public function test_can_reassign_lote_after_unassigning_previous_active_owner(): void
    {
        $ownerAUser = User::factory()->create();
        $ownerAUser->assignRole('propietario');
        $ownerA = Propietario::factory()->create(['user_id' => $ownerAUser->id]);

        $ownerBUser = User::factory()->create();
        $ownerBUser->assignRole('propietario');
        $ownerB = Propietario::factory()->create(['user_id' => $ownerBUser->id]);

        $lote = Lote::factory()->create(['estado' => 'disponible']);

        $service = app(LoteOwnershipAssignmentService::class);
        $service->assign($ownerA, $lote);
        $service->unassign($ownerA, $lote);
        $service->assign($ownerB, $lote);

        $this->assertDatabaseHas('lote_propietario', [
            'propietario_id' => $ownerA->id,
            'lote_id' => $lote->id,
            'status' => 'unassigned',
        ]);

        $this->assertDatabaseHas('lote_propietario', [
            'propietario_id' => $ownerB->id,
            'lote_id' => $lote->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('lotes', [
            'id' => $lote->id,
            'estado' => 'reservado',
        ]);
    }

    public function test_cannot_assign_lote_when_not_disponible(): void
    {
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $ownerUser->id]);

        $lote = Lote::factory()->create(['estado' => 'reservado']);

        $service = app(LoteOwnershipAssignmentService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Solo se pueden asignar lotes en estado disponible.');

        $service->assign($owner, $lote);
    }
}
