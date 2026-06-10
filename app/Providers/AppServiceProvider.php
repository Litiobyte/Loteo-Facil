<?php

namespace App\Providers;

use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use App\Policies\LotePolicy;
use App\Policies\PartnerChargePolicy;
use App\Policies\PropietarioPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Propietario::class, PropietarioPolicy::class);
        Gate::policy(Lote::class, LotePolicy::class);
        Gate::policy(PartnerCharge::class, PartnerChargePolicy::class);
    }
}
