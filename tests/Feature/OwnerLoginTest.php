<?php

namespace Tests\Feature;

use App\Filament\Owner\Auth\ChangePassword;
use App\Filament\Owner\Auth\Login;
use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'propietario'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        CommunitySetting::current();
        Filament::setCurrentPanel(Filament::getPanel('owner'));
    }

    private function ownerWith(string $rut, string $password): User
    {
        $account = User::factory()->create(['password' => $password, 'must_change_password' => true]);
        $account->assignRole('propietario');
        Propietario::factory()->create(['user_id' => $account->id, 'rut' => $rut]);

        return $account;
    }

    public function test_owner_can_login_with_rut_with_dots(): void
    {
        $account = $this->ownerWith('12.345.678-5', '12345678-5perez');
        Livewire::test(Login::class)
            ->set('data.email', '12.345.678-5')
            ->set('data.password', '12345678-5perez')
            ->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($account);
    }

    public function test_owner_can_login_with_email(): void
    {
        $account = $this->ownerWith('11.111.111-1', 'secret-pass');
        Livewire::test(Login::class)
            ->set('data.email', $account->email)
            ->set('data.password', 'secret-pass')
            ->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($account);
    }

    public function test_wrong_password_and_unknown_rut_return_generic_error(): void
    {
        $this->ownerWith('12.345.678-5', '12345678-5perez');
        Livewire::test(Login::class)
            ->set('data.email', '12.345.678-5')
            ->set('data.password', 'otra-clave')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        Livewire::test(Login::class)
            ->set('data.email', '22.222.222-2')
            ->set('data.password', '12345678-5perez')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();
    }

    public function test_must_change_password_blocks_panel_until_changed(): void
    {
        $account = $this->ownerWith('12.345.678-5', '12345678-5perez');
        $this->actingAs($account);
        $this->get('/propietario')->assertRedirect(route('filament.owner.auth.profile'));
        $this->get(route('filament.owner.auth.profile'))->assertOk();

        Livewire::test(ChangePassword::class)
            ->set('data.password', 'Clave-segura-1')
            ->set('data.passwordConfirmation', 'Clave-segura-1')
            ->set('data.currentPassword', '12345678-5perez')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($account->fresh()->must_change_password);
        $this->assertTrue(app('hash')->check('Clave-segura-1', $account->fresh()->password));
        // En producción AuthenticateSession re-almacena el HMAC tras cada request;
        // el sub-request Livewire de este test no ejecuta los middlewares del panel.
        session(['password_hash_web' => Filament::auth()->hashPasswordForCookie($account->fresh()->getAuthPassword())]);
        $this->get('/propietario')->assertOk();
    }

    public function test_wrong_current_password_is_rejected_and_initial_blocked_as_new(): void
    {
        $account = $this->ownerWith('12.345.678-5', '12345678-5perez');
        $this->actingAs($account);
        Livewire::test(ChangePassword::class)
            ->set('data.password', 'Clave-segura-1')
            ->set('data.passwordConfirmation', 'Clave-segura-1')
            ->set('data.currentPassword', 'incorrecta')
            ->call('save')
            ->assertHasErrors();
        $this->assertTrue($account->fresh()->must_change_password);
    }
}
