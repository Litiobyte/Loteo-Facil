<?php

namespace Tests\Feature;

use App\Filament\Owner\Resources\Users\UserResource;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_admin_panel_but_not_owner_panel(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $adminPanel = app(PanelRegistry::class)->get('admin');
        $ownerPanel = app(PanelRegistry::class)->get('owner');

        $this->assertTrue($user->canAccessPanel($adminPanel));
        $this->assertFalse($user->canAccessPanel($ownerPanel));
    }

    public function test_admin_can_access_admin_panel_but_not_owner_panel(): void
    {
        Role::findOrCreate('admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('admin');

        $adminPanel = app(PanelRegistry::class)->get('admin');
        $ownerPanel = app(PanelRegistry::class)->get('owner');

        $this->assertTrue($user->canAccessPanel($adminPanel));
        $this->assertFalse($user->canAccessPanel($ownerPanel));
    }

    public function test_propietario_can_access_owner_panel_but_not_admin_panel(): void
    {
        Role::findOrCreate('propietario', 'web');

        $user = User::factory()->create();
        $user->assignRole('propietario');

        $adminPanel = app(PanelRegistry::class)->get('admin');
        $ownerPanel = app(PanelRegistry::class)->get('owner');

        $this->assertFalse($user->canAccessPanel($adminPanel));
        $this->assertTrue($user->canAccessPanel($ownerPanel));
    }

    public function test_owner_user_resource_is_scoped_to_authenticated_propietario(): void
    {
        Role::findOrCreate('propietario', 'web');

        $ownerA = User::factory()->create();
        $ownerA->assignRole('propietario');

        $ownerB = User::factory()->create();
        $ownerB->assignRole('propietario');

        $this->actingAs($ownerA);

        $query = UserResource::getEloquentQuery();

        $ids = $query->pluck('id')->all();

        $this->assertSame([$ownerA->id], $ids);
        $this->assertNotContains($ownerB->id, $ids);
    }
}
