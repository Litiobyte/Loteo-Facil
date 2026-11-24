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
            ->assertSee('<html lang="es-CL">', escape: false)
            ->assertSee('Software de administración de loteos en Chile | Loteo Fácil', escape: false)
            ->assertSee('La administración de tu loteo,', escape: false)
            ->assertSee('por fin en orden.', escape: false)
            ->assertSee('id="funcionalidades"', escape: false)
            ->assertSee('id="como-funciona"', escape: false)
            ->assertSee('id="modalidades"', escape: false)
            ->assertSee('id="preguntas"', escape: false)
            ->assertSee('Vista ilustrativa del sistema', escape: false)
            ->assertSee('Portal propietario', escape: false)
            ->assertDontSee("Let's get started")
            ->assertDontSee('https://laravel.com/docs');
    }

    public function test_landing_links_to_the_registered_owner_login(): void
    {
        $ownerLogin = route('filament.owner.auth.login');

        $this->assertSame(url('/propietario/login'), $ownerLogin);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.$ownerLogin.'"', escape: false)
            ->assertSee('Acceso a propietarios', escape: false)
            ->assertSee('href="#funcionalidades"', escape: false)
            ->assertSee('href="#como-funciona"', escape: false)
            ->assertSee('href="#preguntas"', escape: false);
    }
}
