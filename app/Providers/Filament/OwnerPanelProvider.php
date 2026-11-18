<?php

namespace App\Providers\Filament;

use App\Filament\Owner\Auth\ChangePassword;
use App\Filament\Owner\Auth\Login;
use App\Filament\Owner\Pages\Dashboard;
use App\Http\Middleware\RequireOwnerPasswordChange;
use App\Models\CommunitySetting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class OwnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('propietario')
            ->login(Login::class)
            ->profile(ChangePassword::class, isSimple: false)
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('filament.auth.switch-login-link', [
                    'label' => 'Eres administrador?',
                    'linkText' => 'Ingresar aqui',
                    'routeName' => 'filament.admin.auth.login',
                ])->render(),
            )
            ->colors([
                'primary' => Color::hex('#1E6432'),
                'success' => Color::hex('#1E6432'),
                'warning' => Color::hex('#936000'),
                'danger' => Color::hex('#B42318'),
                'info' => Color::hex('#0B2E57'),
            ])
            ->font('Montserrat')
            ->brandLogo(fn (): Htmlable => view('filament.branding.brand-logo'))
            ->brandLogoHeight('3.5rem')
            ->favicon(fn (): string => CommunitySetting::logoUrl(icon: true))
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.branding.design-tokens')->render(),
            )
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\Filament\Owner\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\Filament\Owner\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Finanzas')
                    ->icon('heroicon-o-currency-dollar'),
                NavigationGroup::make('Mis Datos')
                    ->icon('heroicon-o-user'),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireOwnerPasswordChange::class,
            ]);
    }
}
