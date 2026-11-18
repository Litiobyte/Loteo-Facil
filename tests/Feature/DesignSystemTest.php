<?php

namespace Tests\Feature;

use Filament\PanelRegistry;
use Filament\Support\Colors\Color;
use Tests\TestCase;

class DesignSystemTest extends TestCase
{
    public function test_design_system_doc_exists(): void
    {
        $this->assertFileExists(base_path('docs/design-system.md'));
    }

    public function test_branding_assets_exist(): void
    {
        $this->assertFileExists(public_path('branding/logo-full-transparent.png'));
        $this->assertFileExists(public_path('branding/icon-transparent.png'));
    }

    public function test_admin_panel_uses_design_system_branding(): void
    {
        $panel = app(PanelRegistry::class)->get('admin');

        $this->assertStringContainsString('branding/logo-full-transparent.png', (string) $panel->getBrandLogo());
        $this->assertSame('3.5rem', $panel->getBrandLogoHeight());
        $this->assertStringEndsWith('branding/icon-transparent.png', (string) $panel->getFavicon());
        $this->assertSame('Montserrat', $panel->getFontFamily());
        $this->assertSame(Color::hex('#1E6432'), $panel->getColors()['primary']);
    }

    public function test_owner_panel_uses_design_system_branding(): void
    {
        $panel = app(PanelRegistry::class)->get('owner');

        $this->assertStringContainsString('branding/logo-full-transparent.png', (string) $panel->getBrandLogo());
        $this->assertSame('3.5rem', $panel->getBrandLogoHeight());
        $this->assertStringEndsWith('branding/icon-transparent.png', (string) $panel->getFavicon());
        $this->assertSame('Montserrat', $panel->getFontFamily());
        $this->assertSame(Color::hex('#1E6432'), $panel->getColors()['primary']);
    }

    public function test_panel_login_pages_render_brand_logo_and_tokens(): void
    {
        $admin = $this->get(route('filament.admin.auth.login'));
        $admin->assertOk();
        $admin->assertSee('branding/logo-full-transparent.png', false);
        $admin->assertSee('--lf-primary', false);

        $owner = $this->get(route('filament.owner.auth.login'));
        $owner->assertOk();
        $owner->assertSee('branding/logo-full-transparent.png', false);
        $owner->assertSee('--lf-primary', false);
    }
}
