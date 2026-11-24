<?php

namespace Tests\Feature\Owner;

use App\Filament\Owner\Resources\Users\Pages\ManageUsers;
use App\Filament\Owner\Resources\Users\UserResource;
use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_table_uses_a_spanish_email_label(): void
    {
        $table = UserResource::table(Table::make($this->createMock(HasTable::class)));

        $this->assertSame('Correo electrónico', $table->getColumn('email')->getLabel());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('propietario', 'web');
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    public function test_profile_renders_real_data_and_missing_field_shortcuts(): void
    {
        [$user, $owner] = $this->createOwner();
        $owner->update(['telefono' => null, 'direccion' => null, 'region_id' => null, 'comuna_id' => null]);
        Propietario::factory()->create(['nombre' => 'Propietario secreto ajeno']);
        $this->actingAs($user)->get(UserResource::getUrl())
            ->assertOk()->assertSee('Datos personales')->assertSee('Información de contacto')
            ->assertSee($owner->nombre)->assertDontSee('Propietario secreto ajeno');

        Livewire::test(ManageUsers::class)
            ->assertViewHas('percentage', 60)
            ->assertViewHas('completed', 6)
            ->assertViewHas('missing', fn (array $missing): bool => array_keys($missing) === ['telefono', 'direccion', 'region_id', 'comuna_id'])
            ->assertSee('href="#profile-telefono"', false)
            ->assertSee('class="op-field op-missing" wire:key="profile-telefono"', false)
            ->assertSee('class="op-field op-missing" wire:key="profile-region_id"', false)
            ->assertSee('<span class="op-todo-icon" aria-hidden="true">!</span>', false)
            ->assertSee('Completar tu información facilita la comunicación')
            ->set('data.telefono', '+56 9 1234 5678')
            ->assertViewHas('percentage', 70)
            ->assertDontSee('class="op-field op-missing" wire:key="profile-telefono"', false)
            ->assertDontSee('href="#profile-telefono"', false)
            ->assertSee('¡Ya casi terminas!');
    }

    public function test_complete_profile_is_calculated_from_all_ten_real_fields(): void
    {
        [$user, $owner] = $this->createOwner();
        $region = Region::query()->create(['nombre' => 'Región de prueba']);
        $commune = Comuna::query()->create(['nombre' => 'Comuna de prueba', 'region_id' => $region->id]);
        $owner->update(['region_id' => $region->id, 'comuna_id' => $commune->id]);
        $this->actingAs($user);

        Livewire::test(ManageUsers::class)->assertViewHas('percentage', 100)
            ->assertViewHas('missing', [])->assertSee('Tu perfil está completo')
            ->assertSee('¡No tienes datos pendientes!')
            ->assertDontSee('<span class="op-todo-icon"', false)
            ->assertDontSee('class="op-field op-missing"', false);
    }

    public function test_save_persists_only_own_contact_and_personal_data(): void
    {
        [$user, $owner] = $this->createOwner();
        $other = Propietario::factory()->create();
        $before = $other->fresh()->getAttributes();
        $this->actingAs($user);

        Livewire::test(ManageUsers::class)
            ->set('data.nombre', 'Nombre actualizado')
            ->set('data.apellido', 'Apellido actualizado')
            ->set('data.email', 'contacto@example.cl')
            ->set('data.telefono', '+56 9 1234 5678')
            ->set('data.rut', '12.345.678-5')
            ->set('data.user_id', $other->user_id)
            ->set('data.id', $other->id)
            ->call('saveProfile')->assertHasNoErrors()->assertSet('saved', true)
            ->assertSee('Tus cambios se guardaron correctamente.');

        $this->assertDatabaseHas('propietarios', [
            'id' => $owner->id, 'user_id' => $user->id, 'nombre' => 'Nombre actualizado',
            'apellido' => 'Apellido actualizado', 'email' => 'contacto@example.cl',
            'telefono' => '+56 9 1234 5678', 'rut' => '12345678-5',
        ]);
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields_are_rejected_without_persisting(string $field, mixed $value): void
    {
        [$user, $owner] = $this->createOwner();
        $before = $owner->fresh()->getAttributes();
        $this->actingAs($user);
        Livewire::test(ManageUsers::class)->set("data.{$field}", $value)
            ->call('saveProfile')->assertHasErrors(["data.{$field}"]);
        $this->assertSame($before, $owner->fresh()->getAttributes());
    }

    public static function invalidFields(): array
    {
        return [
            'blank name' => ['nombre', '   '],
            'bad email' => ['email', 'not-an-email'],
            'bad rut' => ['rut', '12345678-9'],
            'malformed rut' => ['rut', ['invalid']],
            'long phone' => ['telefono', str_repeat('1', 51)],
            'unknown region' => ['region_id', 999999],
        ];
    }

    public function test_duplicate_identifiers_and_commune_from_another_region_are_rejected(): void
    {
        [$user] = $this->createOwner();
        $other = Propietario::factory()->create(['rut' => '11111111-1']);
        $region = Region::query()->create(['nombre' => 'Primera']);
        $otherRegion = Region::query()->create(['nombre' => 'Segunda']);
        $commune = Comuna::query()->create(['nombre' => 'Ajena', 'region_id' => $otherRegion->id]);
        $this->actingAs($user);

        Livewire::test(ManageUsers::class)->set('data.email', $other->email)
            ->set('data.rut', '11.111.111-1')
            ->set('data.region_id', $region->id)->set('data.comuna_id', $commune->id)
            ->call('saveProfile')->assertHasErrors(['data.email', 'data.rut', 'data.comuna_id']);
    }

    public function test_region_change_resets_commune_and_optional_fields_can_be_cleared(): void
    {
        [$user, $owner] = $this->createOwner();
        $region = Region::query()->create(['nombre' => 'Nueva región']);
        $this->actingAs($user);
        Livewire::test(ManageUsers::class)
            ->set('data.region_id', $region->id)->assertSet('data.comuna_id', null)
            ->set('data.telefono', '')->set('data.profesion', '')
            ->call('saveProfile')->assertHasNoErrors();
        $this->assertNull($owner->fresh()->telefono);
        $this->assertNull($owner->fresh()->profesion);
    }

    public function test_profile_permission_does_not_grant_general_owner_or_user_editing(): void
    {
        [$user, $owner] = $this->createOwner();
        $other = Propietario::factory()->create();
        $this->assertTrue(Gate::forUser($user)->allows('updateProfile', $owner));
        $this->assertFalse(Gate::forUser($user)->allows('updateProfile', $other));
        $this->assertFalse(Gate::forUser($user)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($user)->allows('update', $user));
    }

    public function test_missing_owner_has_clear_empty_state_and_cannot_create_records(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);
        Livewire::test(ManageUsers::class)->assertSee('No tienes un propietario asociado')
            ->assertDontSee('Guardar cambios')->call('saveProfile')->assertForbidden();
        $this->assertDatabaseCount('propietarios', 0);
    }

    public function test_guests_and_other_roles_cannot_access_profile(): void
    {
        $this->get(UserResource::getUrl())->assertRedirect();
        $this->actingAs(User::factory()->create())->get(UserResource::getUrl())->assertForbidden();
        Livewire::test(ManageUsers::class)->assertForbidden();
    }

    public function test_incomplete_profile_shows_warning_badge_in_navigation(): void
    {
        [$user, $owner] = $this->createOwner();
        $this->actingAs($user);

        $this->assertFalse($owner->isProfileComplete());
        $this->assertSame('!', UserResource::getNavigationBadge());
        $this->assertSame('warning', UserResource::getNavigationBadgeColor());
        $this->assertSame('Completa tu información', UserResource::getNavigationBadgeTooltip());

        $view = view('filament.owner.profile-alert-badge')->render();
        $this->assertStringContainsString("badge.textContent = '!'", $view);
        $this->assertStringNotContainsString('badge.remove();', $view);
    }

    public function test_complete_profile_hides_navigation_and_avatar_badges(): void
    {
        [$user, $owner] = $this->createOwner();
        $region = Region::query()->create(['nombre' => 'Región de prueba']);
        $commune = Comuna::query()->create(['nombre' => 'Comuna de prueba', 'region_id' => $region->id]);
        $owner->update(['telefono' => '+56 9 1234 5678', 'direccion' => 'Calle Falsa 123', 'region_id' => $region->id, 'comuna_id' => $commune->id]);
        $this->actingAs($user);

        $this->assertTrue($owner->isProfileComplete());
        $this->assertNull(UserResource::getNavigationBadge());
        $this->assertNull(UserResource::getNavigationBadgeTooltip());

        $view = view('filament.owner.profile-alert-badge')->render();
        $this->assertStringContainsString('badge.remove();', $view);
        $this->assertStringNotContainsString("badge.textContent = '!'", $view);
    }

    public function test_missing_owner_relation_never_shows_profile_badges(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $this->assertNull(UserResource::getNavigationBadge());

        $view = view('filament.owner.profile-alert-badge')->render();
        $this->assertStringContainsString('badge.remove();', $view);
    }

    /** @return array{User, Propietario} */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        return [$user, Propietario::factory()->create(['user_id' => $user->id, 'rut' => '12345678-5'])];
    }
}
