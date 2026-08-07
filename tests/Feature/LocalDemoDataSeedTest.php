<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDemoDataSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_environment_seeds_demo_data(): void
    {
        app()->instance('env', 'local');

        $this->seed();

        $this->assertGreaterThan(1, User::query()->count());
        $this->assertGreaterThan(0, Lote::query()->count());
        $this->assertGreaterThan(0, Propietario::query()->count());
        $this->assertGreaterThan(0, ExpenseCategory::query()->count());
        $this->assertGreaterThan(0, Expense::query()->count());
        $this->assertSame(0, PartnerCharge::query()->count());
    }
}
