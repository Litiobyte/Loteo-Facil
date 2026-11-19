<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_guests_can_read_the_public_owner_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('welcome')
            ->assertSee('<html lang="es">', escape: false)
            ->assertSee('branding/logo-full-transparent.png', escape: false)
            ->assertSee('Tu comunidad,')
            ->assertSee('Mis cobros')
            ->assertSee('Mis pagos')
            ->assertSee('Mi estado de cuenta')
            ->assertSee('Tu participación')
            ->assertSee('Vista ilustrativa')
            ->assertSee('Solicita tu cuenta a la administración de tu comunidad.')
            ->assertDontSee("Let's get started")
            ->assertDontSee('https://laravel.com/docs');
    }

    public function test_landing_links_to_the_registered_owner_and_admin_logins(): void
    {
        $ownerLogin = route('filament.owner.auth.login');
        $adminLogin = route('filament.admin.auth.login');

        $this->assertSame(url('/propietario/login'), $ownerLogin);
        $this->assertSame(url('/admin/login'), $adminLogin);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.$ownerLogin.'"', escape: false)
            ->assertSee('href="'.$adminLogin.'"', escape: false)
            ->assertSee('Soy propietario')
            ->assertSee('Soy administrador')
            ->assertSee('href="#contenido"', escape: false)
            ->assertSee('id="contenido"', escape: false)
            ->assertSee('href="#beneficios"', escape: false)
            ->assertSee('id="beneficios"', escape: false);
    }
}
