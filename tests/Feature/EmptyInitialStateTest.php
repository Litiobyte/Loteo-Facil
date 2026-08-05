<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Collection;
use App\Models\Comuna;
use App\Models\Etapa;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmptyInitialStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_only_contains_super_admin_user(): void
    {
        $this->seed();

        $this->assertSame(1, User::query()->count());

        $superAdmin = User::query()->first();

        $this->assertSame('superadmin@loteofacil.cl', $superAdmin->email);
        $this->assertTrue($superAdmin->hasRole('super_admin'));
    }

    public function test_fresh_install_has_no_demo_data_or_financial_records(): void
    {
        $this->seed();

        $this->assertSame(0, ExpenseCategory::query()->count());
        $this->assertSame(0, Lote::query()->count());
        $this->assertSame(0, Propietario::query()->count());
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, PartnerCharge::query()->count());
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    public function test_fresh_install_seeds_reference_data(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Region::query()->count());
        $this->assertGreaterThan(0, Comuna::query()->count());
        $this->assertGreaterThan(0, Etapa::query()->count());
    }
}
