<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_superadmin_with_a_hashed_password_without_demo_data(): void
    {
        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Nombre', 'Administradora')
            ->expectsQuestion('Correo electrónico', ' NUEVA@EXAMPLE.COM ')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas y números)', 'SeguraParaTests123!')
            ->expectsQuestion('Confirmar contraseña', 'SeguraParaTests123!')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('nueva@example.com', $user->email);
        $this->assertTrue(Hash::check('SeguraParaTests123!', $user->password));
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('propietarios', 0);
        $this->assertDatabaseCount('partner_charges', 0);
    }

    public function test_it_does_not_modify_or_promote_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $password = $existing->password;

        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Nombre', 'Otro nombre')
            ->expectsQuestion('Correo electrónico', $existing->email)
            ->assertFailed();

        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame($existing->name, $existing->fresh()->name);
        $this->assertFalse($existing->fresh()->hasRole('super_admin'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Nombre', 'Administrador')
            ->expectsQuestion('Correo electrónico', 'new@example.com')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas y números)', 'password')
            ->expectsQuestion('Confirmar contraseña', 'password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_it_rejects_a_password_confirmation_mismatch(): void
    {
        $this->artisan('app:create-super-admin')
            ->expectsQuestion('Nombre', 'Administrador')
            ->expectsQuestion('Correo electrónico', 'new@example.com')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas y números)', 'SeguraParaTests123!')
            ->expectsQuestion('Confirmar contraseña', 'OtraClaveParaTests123!')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_non_interactive_execution(): void
    {
        $this->artisan('app:create-super-admin', ['--no-interaction' => true])
            ->expectsOutput('Ejecuta este comando en una terminal interactiva; la contraseña se solicita oculta.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
