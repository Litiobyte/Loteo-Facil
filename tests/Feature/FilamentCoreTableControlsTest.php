<?php

namespace Tests\Feature;

use App\Filament\Resources\Lotes\Pages\ManageLotes;
use App\Filament\Resources\Propietarios\Pages\ListPropietarios;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentCoreTableControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_core_admin_tables_offer_filters_and_secondary_column_selection(): void
    {
        foreach ([ManageLotes::class, ListPropietarios::class, ManageUsers::class] as $page) {
            $table = Livewire::test($page)->assertOk()->instance()->getTable();

            $this->assertNotEmpty($table->getFilters(), $page);
            $this->assertTrue($table->hasColumnManager(), $page);
            $this->assertNotEmpty(array_filter($table->getColumns(), fn ($column): bool => $column->isToggleable()), $page);
            $this->assertTrue($table->isSearchable(), $page);

            foreach ($table->getColumns() as $column) {
                $this->assertSame('fit-content', $column->getWidth(), $page);
                $this->assertSame('width: fit-content; max-width: 15.625rem; overflow-wrap: anywhere;', $column->getExtraCellAttributes()['style'], $page);
                $this->assertTrue($column->canWrap(), $page);
            }
        }
    }

    public function test_lotes_can_be_filtered_by_estado_and_etapa(): void
    {
        $available = Lote::factory()->create(['estado' => 'disponible']);
        $sold = Lote::factory()->create(['estado' => 'vendido']);

        Livewire::test(ManageLotes::class)
            ->filterTable('estado', 'disponible')
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$sold])
            ->resetTableFilters()
            ->filterTable('etapa', $sold->etapa_id)
            ->assertCanSeeTableRecords([$sold]);
    }

    public function test_propietarios_can_be_filtered_by_region_and_searched_by_surname(): void
    {
        $region = Region::query()->create(['nombre' => 'Region A']);
        $otherRegion = Region::query()->create(['nombre' => 'Region B']);
        $owner = Propietario::factory()->create(['region_id' => $region->id, 'apellido' => 'Zetaprueba']);
        $other = Propietario::factory()->create(['region_id' => $otherRegion->id, 'apellido' => 'Alfaprueba']);

        Livewire::test(ListPropietarios::class)
            ->filterTable('region', $region->id)
            ->assertCanSeeTableRecords([$owner])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->searchTable('Zetaprueba')
            ->assertCanSeeTableRecords([$owner])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_propietarios_can_be_sorted_by_name_rut_and_email(): void
    {
        $first = Propietario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Alfa', 'rut' => '11111111-1', 'email' => 'a@example.test']);
        $second = Propietario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Zeta', 'rut' => '22222222-2', 'email' => 'b@example.test']);

        $page = Livewire::test(ListPropietarios::class);

        foreach (['nombre_completo', 'rut', 'email'] as $column) {
            $this->assertTrue($page->instance()->getTable()->getColumn($column)->isSortable());
            $page->sortTable($column, 'asc')
                ->assertCanSeeTableRecords([$first, $second], inOrder: true)
                ->sortTable($column, 'desc')
                ->assertCanSeeTableRecords([$second, $first], inOrder: true);
        }
    }

    public function test_users_can_be_sorted_by_name_email_and_role(): void
    {
        $first = User::factory()->create(['name' => 'Ana', 'email' => 'a@example.test']);
        $first->assignRole('admin');
        $second = User::factory()->create(['name' => 'Zoe', 'email' => 'z@example.test']);
        $second->assignRole('propietario');

        $page = Livewire::test(ManageUsers::class);

        foreach (['name', 'email', 'roles.name'] as $column) {
            $this->assertTrue($page->instance()->getTable()->getColumn($column)->isSortable());
            $page->sortTable($column, 'asc')
                ->assertCanSeeTableRecords([$first, $second], inOrder: true)
                ->sortTable($column, 'desc')
                ->assertCanSeeTableRecords([$second, $first], inOrder: true);
        }
    }

    public function test_role_filter_does_not_bypass_admin_user_scope(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('propietario');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::test(ManageUsers::class)
            ->filterTable('roles', Role::findByName('propietario', 'web')->id)
            ->assertCanSeeTableRecords([$owner])
            ->assertCanNotSeeTableRecords([$admin]);

        $this->actingAs($admin);
        Livewire::test(ManageUsers::class)
            ->filterTable('roles', Role::findByName('super_admin', 'web')->id)
            ->assertCountTableRecords(0);
    }
}
