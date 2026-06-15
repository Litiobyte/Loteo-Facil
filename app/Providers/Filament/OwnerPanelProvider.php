<?php

namespace App\Providers\Filament;

use App\Filament\Owner\Widgets\FinancialSummaryWidget;
use App\Filament\Owner\Widgets\NextCollectionEstimateWidget;
use App\Filament\Owner\Widgets\OverdueAlertsWidget;
use App\Filament\Owner\Widgets\WelcomeWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
            ->login()
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('filament.auth.switch-login-link', [
                    'label' => 'Eres administrador?',
                    'linkText' => 'Ingresar aqui',
                    'routeName' => 'filament.admin.auth.login',
                ])->render(),
            )
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\Filament\Owner\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\Filament\Owner\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                WelcomeWidget::class,
                OverdueAlertsWidget::class,
                FinancialSummaryWidget::class,
                NextCollectionEstimateWidget::class,
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
            ]);
    }
}
