<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\CashOnHandStatWidget;
use App\Filament\Admin\Widgets\CollectionsTrendChartWidget;
use App\Filament\Admin\Widgets\DelinquentOwnersStatWidget;
use App\Filament\Admin\Widgets\LatestCollectionsTableWidget;
use App\Filament\Admin\Widgets\LotsSummaryStatWidget;
use App\Filament\Admin\Widgets\OverduePortfolioStatWidget;
use App\Filament\Admin\Widgets\OwnersOnTimeStatWidget;
use App\Filament\Admin\Widgets\PendingPortfolioStatWidget;
use App\Filament\Admin\Widgets\TopDelinquentOwnersWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('filament.auth.switch-login-link', [
                    'label' => 'Eres propietario?',
                    'linkText' => 'Ingresar aqui',
                    'routeName' => 'filament.owner.auth.login',
                ])->render(),
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                OverduePortfolioStatWidget::class,
                OwnersOnTimeStatWidget::class,
                DelinquentOwnersStatWidget::class,
                CashOnHandStatWidget::class,
                PendingPortfolioStatWidget::class,
                LotsSummaryStatWidget::class,
                CollectionsTrendChartWidget::class,
                TopDelinquentOwnersWidget::class,
                LatestCollectionsTableWidget::class,
            ])
            ->sidebarWidth('15rem')
            ->sidebarCollapsibleOnDesktop()
            ->collapsedSidebarWidth('5rem')
            ->navigationGroups([
                NavigationGroup::make('Gastos Comunes'),
                NavigationGroup::make('Finanzas'),
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
