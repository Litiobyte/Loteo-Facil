<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\Services\CollectionImportService;
use App\Domain\Imports\Services\LoteImportService;
use App\Domain\Imports\Services\PropietarioImportService;
use App\Filament\Admin\Pages\Importaciones;
use App\Filament\Admin\Pages\Importaciones\ImportarLotes;
use App\Filament\Admin\Pages\Importaciones\ImportarPropietarios;
use App\Filament\Admin\Pages\Importaciones\ImportarRecaudaciones;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentImportacionesPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_pages_are_registered_in_admin_panel(): void
    {
        $panel = app(PanelRegistry::class)->get('admin');

        $this->assertContains(Importaciones::class, $panel->getPages());
        $this->assertContains(ImportarLotes::class, $panel->getPages());
        $this->assertContains(ImportarPropietarios::class, $panel->getPages());
        $this->assertContains(ImportarRecaudaciones::class, $panel->getPages());
    }

    public function test_import_pages_only_accessible_to_super_admin(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');

        $pages = [ImportarLotes::class, ImportarPropietarios::class, ImportarRecaudaciones::class];

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        foreach ($pages as $page) {
            $this->assertTrue($page::canAccess());
            $this->assertTrue($page::shouldRegisterNavigation());
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        foreach ($pages as $page) {
            $this->assertFalse($page::canAccess());
            $this->assertFalse($page::shouldRegisterNavigation());
        }
    }

    public function test_import_pages_render_for_super_admin(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        $this->get(ImportarLotes::getUrl())->assertOk();
        $this->get(ImportarPropietarios::getUrl())->assertOk();
        $this->get(ImportarRecaudaciones::getUrl())->assertOk();
    }

    public function test_import_pages_redirect_unauthenticated_users(): void
    {
        $this->get(ImportarLotes::getUrl())->assertRedirect();
    }

    public function test_page_templates_use_service_headers(): void
    {
        $this->assertSame(LoteImportService::HEADERS, ImportarLotes::templateHeaders());
        $this->assertSame(PropietarioImportService::HEADERS, ImportarPropietarios::templateHeaders());
        $this->assertSame(CollectionImportService::HEADERS, ImportarRecaudaciones::templateHeaders());
    }

    public function test_import_pages_belong_to_importaciones_navigation_group(): void
    {
        $this->assertSame('Importaciones', Importaciones::getNavigationGroup());
        $this->assertSame('Importaciones', ImportarLotes::getNavigationGroup());
        $this->assertSame('Importaciones', ImportarPropietarios::getNavigationGroup());
        $this->assertSame('Importaciones', ImportarRecaudaciones::getNavigationGroup());
    }

    public function test_import_page_renders_result_data(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test(ImportarLotes::class)
            ->set('resultData', [
                'created' => 2,
                'updated' => 1,
                'errors' => [
                    ['row' => 2, 'field' => 'estado', 'message' => 'El campo estado tiene un valor no permitido.'],
                ],
            ])
            ->assertOk()
            ->assertSee('Resultado de la importación')
            ->assertSee('Total de filas')
            ->assertSee('Creados')
            ->assertSee('Actualizados')
            ->assertSee('Con errores')
            ->assertSee('Detalle de errores')
            ->assertSee('Fila 2')
            ->assertSee('El campo estado tiene un valor no permitido.');
    }

    public function test_import_pages_render_header_actions(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        foreach ([ImportarLotes::class, ImportarPropietarios::class, ImportarRecaudaciones::class] as $page) {
            Livewire::test($page)
                ->assertOk()
                ->assertSee('Descargar plantilla')
                ->assertSee('Importar archivo');
        }
    }

    public function test_import_file_input_does_not_restrict_file_types_client_side(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test(ImportarLotes::class)
            ->assertOk()
            ->assertDontSee('.csv,.xlsx,.xls')
            ->assertDontSee('accept="');
    }
}
