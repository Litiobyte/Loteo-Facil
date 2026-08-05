<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Importaciones;
use App\Filament\Admin\Pages\Proveedores;
use App\Filament\Resources\Lotes\LoteResource;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administracion_navigation_group_is_configured_for_dashboard_and_catalog_resources(): void
    {
        $this->assertSame('Administración', Dashboard::getNavigationGroup());
        $this->assertSame(0, Dashboard::getNavigationSort());

        $this->assertSame('Administración', LoteResource::getNavigationGroup());
        $this->assertSame(10, LoteResource::getNavigationSort());

        $this->assertSame('Administración', PropietarioResource::getNavigationGroup());
        $this->assertSame(20, PropietarioResource::getNavigationSort());

        $this->assertSame('Administración', UserResource::getNavigationGroup());
        $this->assertSame(30, UserResource::getNavigationSort());
    }

    public function test_proveedores_placeholder_is_registered_in_gastos_group(): void
    {
        $this->assertSame('Gastos', Proveedores::getNavigationGroup());
        $this->assertSame(40, Proveedores::getNavigationSort());

        $panel = app(PanelRegistry::class)->get('admin');

        $this->assertContains(Proveedores::class, $panel->getPages());
    }

    public function test_importaciones_page_is_registered_in_admin_panel(): void
    {
        $panel = app(PanelRegistry::class)->get('admin');

        $this->assertContains(Importaciones::class, $panel->getPages());
    }

    public function test_importaciones_page_is_only_accessible_to_super_admin(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        $this->assertTrue(Importaciones::canAccess());
        $this->assertTrue(Importaciones::shouldRegisterNavigation());

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $this->assertFalse(Importaciones::canAccess());
        $this->assertFalse(Importaciones::shouldRegisterNavigation());
    }
}
