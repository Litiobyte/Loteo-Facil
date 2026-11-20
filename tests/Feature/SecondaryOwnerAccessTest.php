<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Domain\Owners\Services\PropietarioRegistrationService;
use App\Filament\Owner\Auth\Login;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Filament\Owner\Resources\Users\Pages\ManageUsers as OwnerProfile;
use App\Filament\Resources\Users\Pages\ManageUsers as AdminUsers;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecondaryOwnerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'admin', 'propietario'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_secondary_account_sees_same_owner_data_but_cannot_change_profile(): void
    {
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $secondary = User::factory()->create();
        $secondary->assignRole('propietario');
        $secondary->forceFill(['secondary_propietario_id' => $owner->id])->save();
        $anotherSecondary = User::factory()->create();
        $anotherSecondary->assignRole('propietario');
        $anotherSecondary->forceFill(['secondary_propietario_id' => $owner->id])->save();
        $ownCharge = PartnerCharge::factory()->create(['propietario_id' => $owner->id]);
        $otherCharge = PartnerCharge::factory()->create();

        $this->actingAs($secondary);
        Filament::setCurrentPanel(Filament::getPanel('owner'));

        $this->assertTrue($secondary->fresh()->propietario->is($owner));
        $this->assertTrue($anotherSecondary->fresh()->propietario->is($owner));
        $this->assertSame(2, $owner->secondaryUsers()->count());
        $this->assertTrue(MisCobrosResource::getEloquentQuery()->whereKey($ownCharge)->exists());
        $this->assertFalse(MisCobrosResource::getEloquentQuery()->whereKey($otherCharge)->exists());
        $this->assertFalse(MisCobrosResource::canView($otherCharge));
        $this->get(MisCobrosResource::getUrl('view', ['record' => $ownCharge]))->assertOk();
        $this->get(MisCobrosResource::getUrl('view', ['record' => $otherCharge]))->assertNotFound();
        Livewire::test(OwnerProfile::class)
            ->assertSee($owner->nombre)
            ->assertDontSee('Guardar cambios')
            ->set('data.nombre', 'Modificado')
            ->call('saveProfile')->assertForbidden();
        $this->assertNotSame('Modificado', $owner->fresh()->nombre);
        $this->assertFalse(app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query())->whereKey($secondary)->exists());
    }

    public function test_admin_can_link_and_unlink_a_secondary_account(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = Propietario::factory()->create();
        $secondary = User::factory()->create();
        $secondary->assignRole('propietario');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $data = ['name' => $secondary->name, 'email' => $secondary->email, 'role' => 'propietario', 'secondary_propietario_id' => $owner->id];
        Livewire::test(AdminUsers::class)->callTableAction('edit', $secondary, $data)->assertHasNoActionErrors();
        $this->assertTrue($secondary->fresh()->propietario->is($owner));

        $data['secondary_propietario_id'] = null;
        Livewire::test(AdminUsers::class)->callTableAction('edit', $secondary, $data)->assertHasNoActionErrors();
        $this->assertNull($secondary->fresh()->propietario);
    }

    public function test_admin_cannot_link_a_primary_account_as_secondary(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $other = Propietario::factory()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)->callTableAction('edit', $owner->user, [
            'name' => $owner->user->name,
            'email' => $owner->user->email,
            'role' => 'propietario',
            'secondary_propietario_id' => $other->id,
        ])->assertHasNoActionErrors();
        $this->assertNull($owner->user->fresh()->secondary_propietario_id);
    }

    public function test_edit_form_hides_owner_selector_for_a_primary_account(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)
            ->mountTableAction('edit', $owner->user)
            ->assertFormFieldIsHidden('secondary_propietario_id');
    }

    public function test_admin_can_select_an_owner_when_creating_a_secondary_account(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = Propietario::factory()->create();
        $primaryUserId = $owner->user_id;
        $ownerCount = Propietario::query()->count();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)
            ->mountAction('create')
            ->assertFormFieldIsVisible('secondary_propietario_id')
            ->fillForm([
                'name' => 'Cuenta secundaria',
                'email' => 'secondary@example.com',
                'password' => 'secure-password',
                'role' => 'propietario',
                'secondary_propietario_id' => $owner->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $secondary = User::query()->where('email', 'secondary@example.com')->firstOrFail();
        $this->assertEquals($owner->id, $secondary->secondary_propietario_id);
        $this->assertTrue($secondary->hasRole('propietario'));
        $this->assertTrue($secondary->propietario->is($owner));
        $this->assertFalse($secondary->propietario()->exists());
        $this->assertEquals($primaryUserId, $owner->fresh()->user_id);
        $this->assertSame($ownerCount, Propietario::query()->count());
    }

    public function test_admin_can_create_an_unassociated_owner_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)->callAction('create', [
            'name' => 'Sin asociación',
            'email' => 'unassociated@example.com',
            'password' => 'secure-password',
            'role' => 'propietario',
        ])->assertHasNoActionErrors();

        $user = User::query()->where('email', 'unassociated@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('propietario'));
        $this->assertNull($user->secondary_propietario_id);
        $this->assertNull($user->propietario);
    }

    public function test_create_rejects_a_nonexistent_associated_owner(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)->callAction('create', [
            'name' => 'Propietario inválido',
            'email' => 'invalid-owner@example.com',
            'password' => 'secure-password',
            'role' => 'propietario',
            'secondary_propietario_id' => 999999,
        ])->assertHasActionErrors(['secondary_propietario_id']);

        $this->assertDatabaseMissing('users', ['email' => 'invalid-owner@example.com']);
    }

    public function test_create_rejects_secondary_association_for_an_admin_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $owner = Propietario::factory()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)->callAction('create', [
            'name' => 'Administrador',
            'email' => 'invalid-role@example.com',
            'password' => 'secure-password',
            'role' => 'admin',
            'secondary_propietario_id' => $owner->id,
        ])->assertHasActionErrors(['secondary_propietario_id']);

        $this->assertDatabaseMissing('users', ['email' => 'invalid-role@example.com']);
    }

    public function test_admin_cannot_create_a_secondary_user_with_a_privileged_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = Propietario::factory()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminUsers::class)->callAction('create', [
            'name' => 'Rol no permitido',
            'email' => 'forbidden-role@example.com',
            'password' => 'secure-password',
            'role' => 'super_admin',
            'secondary_propietario_id' => $owner->id,
        ])->assertHasActionErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'forbidden-role@example.com']);
    }

    public function test_secondary_user_can_sign_in_using_own_email(): void
    {
        $owner = Propietario::factory()->create();
        $secondary = User::factory()->create(['password' => 'secure-password']);
        $secondary->assignRole('propietario');
        $secondary->forceFill(['secondary_propietario_id' => $owner->id])->save();
        Filament::setCurrentPanel(Filament::getPanel('owner'));

        Livewire::test(Login::class)
            ->set('data.email', $secondary->email)
            ->set('data.password', 'secure-password')
            ->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($secondary);
    }

    public function test_secondary_cannot_be_registered_as_primary(): void
    {
        $owner = Propietario::factory()->create();
        $secondary = User::factory()->create();
        $secondary->assignRole('propietario');
        $secondary->forceFill(['secondary_propietario_id' => $owner->id])->save();

        $this->expectException(\DomainException::class);
        app(PropietarioRegistrationService::class)->register($secondary);
    }

    public function test_secondary_cannot_open_admin_user_management(): void
    {
        $owner = Propietario::factory()->create();
        $secondary = User::factory()->create();
        $secondary->assignRole('propietario');
        $secondary->forceFill(['secondary_propietario_id' => $owner->id])->save();
        $this->actingAs($secondary);

        $this->get('/admin/users')->assertForbidden();
    }
}
