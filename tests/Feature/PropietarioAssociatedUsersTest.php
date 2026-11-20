<?php

namespace Tests\Feature;

use App\Filament\Resources\Propietarios\Pages\EditPropietario;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Filament\Resources\Propietarios\RelationManagers\AssociatedUsersRelationManager;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PropietarioAssociatedUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tab_lists_primary_and_secondary_users_without_other_owners_accounts(): void
    {
        $owner = Propietario::factory()->create();
        $secondary = User::factory()->count(2)->create();
        foreach ($secondary as $user) {
            $user->forceFill(['secondary_propietario_id' => $owner->id])->save();
        }
        $otherOwner = Propietario::factory()->create();
        $otherSecondary = User::factory()->create();
        $otherSecondary->forceFill(['secondary_propietario_id' => $otherOwner->id])->save();

        Livewire::test(EditPropietario::class, ['record' => $owner->getRouteKey()])
            ->assertOk()
            ->assertSee('Usuarios asociados');

        $component = Livewire::test(AssociatedUsersRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass' => EditPropietario::class,
        ])
            ->assertCanSeeTableRecords([$owner->user, ...$secondary])
            ->assertCanNotSeeTableRecords([$otherOwner->user, $otherSecondary])
            ->assertCountTableRecords(3)
            ->assertTableColumnStateSet('association_type', 'Principal', $owner->user)
            ->assertTableColumnStateSet('association_type', 'Secundario', $secondary->first())
            ->assertTableColumnStateSet('email', $secondary->first()->email, $secondary->first())
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('associate')
            ->assertTableActionDoesNotExist('dissociate')
            ->assertTableActionDoesNotExist('create')
            ->searchTable($secondary->first()->email)
            ->assertCanSeeTableRecords([$secondary->first()])
            ->assertCountTableRecords(1);

        $this->assertTrue($component->instance()->isReadOnly());
        $this->assertEmpty($component->instance()->getTable()->getHeaderActions());
        $this->assertEmpty($component->instance()->getTable()->getToolbarActions());
    }

    public function test_tab_shows_primary_user_when_there_are_no_secondary_users(): void
    {
        $owner = Propietario::factory()->create();

        Livewire::test(AssociatedUsersRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass' => EditPropietario::class,
        ])
            ->assertCanSeeTableRecords([$owner->user])
            ->assertCountTableRecords(1)
            ->assertTableColumnStateSet('association_type', 'Principal', $owner->user);
    }

    public function test_tab_respects_owner_resource_authorization(): void
    {
        $owner = Propietario::factory()->create();
        $this->assertTrue(AssociatedUsersRelationManager::canViewForRecord($owner, EditPropietario::class));

        $owner->user->assignRole('propietario');
        $this->actingAs($owner->user);

        $this->assertFalse(AssociatedUsersRelationManager::canViewForRecord($owner, EditPropietario::class));
        $this->get(PropietarioResource::getUrl('edit', ['record' => $owner]))->assertForbidden();
    }
}
