<?php

namespace App\Providers;

use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentTypePolicy;
use App\Policies\LotePolicy;
use App\Policies\PartnerChargePolicy;
use App\Policies\PropietarioPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        Relation::morphMap([
            Propietario::class => Propietario::class,
            ShareHolding::class => ShareHolding::class,
            ShareMovement::class => ShareMovement::class,
            Lote::class => Lote::class,
            Expense::class => Expense::class,
            ExpenseFundingPayment::class => ExpenseFundingPayment::class,
            Collection::class => Collection::class,
            CommunitySetting::class => CommunitySetting::class,
            PartnerCharge::class => PartnerCharge::class,
        ]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Propietario::class, PropietarioPolicy::class);
        Gate::policy(Lote::class, LotePolicy::class);
        Gate::policy(PartnerCharge::class, PartnerChargePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(DocumentType::class, DocumentTypePolicy::class);
    }
}
