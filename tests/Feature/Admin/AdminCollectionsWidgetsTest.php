<?php

namespace Tests\Feature\Admin;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Admin\Widgets\CashOnHandStatWidget;
use App\Filament\Admin\Widgets\CollectionsTrendChartWidget;
use App\Filament\Admin\Widgets\DelinquentOwnersStatWidget;
use App\Filament\Admin\Widgets\LatestCollectionsTableWidget;
use App\Filament\Admin\Widgets\LotsSummaryStatWidget;
use App\Filament\Admin\Widgets\OverduePortfolioStatWidget;
use App\Filament\Admin\Widgets\OwnersOnTimeStatWidget;
use App\Filament\Admin\Widgets\PendingPortfolioStatWidget;
use App\Filament\Admin\Widgets\TopDelinquentOwnersWidget;
use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\Etapa;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCollectionsWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_admin_collections_summary_widget_calculates_core_metrics(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = Propietario::factory()->create();
        $ownerTwo = Propietario::factory()->create();
        $ownerThree = Propietario::factory()->create();

        $this->attachActiveSoldLot($owner);
        $this->attachActiveSoldLot($ownerTwo);
        $this->attachActiveSoldLot($ownerThree);

        Expense::factory()->distributed()->create([
            'amount' => 1000000,
            'funded_amount' => 1000000,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        Expense::factory()->distributed()->create([
            'amount' => 500000,
            'funded_amount' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $nonOverdueExpenseForCharges = Expense::factory()->distributed()->create([
            'amount' => 200000,
            'funded_amount' => 0,
            'due_date' => now()->addDays(20)->toDateString(),
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'expense_id' => $nonOverdueExpenseForCharges->id,
            'status' => ChargeStatus::Pending->value,
            'amount' => 100000,
            'paid_amount' => 0,
            'due_date' => now()->subDays(15)->toDateString(),
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $ownerTwo->id,
            'expense_id' => $nonOverdueExpenseForCharges->id,
            'status' => ChargeStatus::Partial->value,
            'amount' => 80000,
            'paid_amount' => 30000,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100000,
            'applied_amount' => 70000,
            'unapplied_amount' => 30000,
            'status' => CollectionStatus::PartiallyApplied->value,
        ]);

        ExpenseFundingPayment::factory()->create([
            'expense_id' => $nonOverdueExpenseForCharges->id,
            'amount' => 40000,
            'is_void' => false,
        ]);

        $this->actingAs($admin);

        $overdueStats = $this->invokeProtectedMethod(new OverduePortfolioStatWidget, 'getStats');
        $onTimeStats = $this->invokeProtectedMethod(new OwnersOnTimeStatWidget, 'getStats');
        $delinquentStats = $this->invokeProtectedMethod(new DelinquentOwnersStatWidget, 'getStats');
        $cashStats = $this->invokeProtectedMethod(new CashOnHandStatWidget, 'getStats');
        $pendingStats = $this->invokeProtectedMethod(new PendingPortfolioStatWidget, 'getStats');
        $lotsStats = $this->invokeProtectedMethod(new LotsSummaryStatWidget, 'getStats');

        $this->assertSame('0', preg_replace('/\D/', '', (string) $overdueStats[0]->getValue()));
        $this->assertSame('1', preg_replace('/\D/', '', (string) $onTimeStats[0]->getValue()));
        $this->assertSame('2', preg_replace('/\D/', '', (string) $delinquentStats[0]->getValue()));
        $this->assertSame('60000', preg_replace('/\D/', '', (string) $cashStats[0]->getValue()));
        $this->assertSame('150000', preg_replace('/\D/', '', (string) $pendingStats[0]->getValue()));
        $this->assertSame('3', preg_replace('/\D/', '', (string) $lotsStats[0]->getValue()));
        $this->assertStringContainsString('Vendidos: 3 | Disponibles: 0 | Reservados: 0', (string) $lotsStats[0]->getDescription());
    }

    public function test_overdue_balance_counts_only_overdue_unpaid_expense_amounts_due_date_lte_today(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Expense::factory()->distributed()->create([
            'amount' => 1000000,
            'funded_amount' => 1000000,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        Expense::factory()->distributed()->create([
            'amount' => 500000,
            'funded_amount' => 0,
            'due_date' => now()->toDateString(),
        ]);

        Expense::factory()->distributed()->create([
            'amount' => 300000,
            'funded_amount' => 0,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($admin);

        $stats = $this->invokeProtectedMethod(new OverduePortfolioStatWidget, 'getStats');

        $this->assertCount(1, $stats);
        $this->assertSame('500000', preg_replace('/\D/', '', (string) $stats[0]->getValue()));
    }

    public function test_overdue_balance_uses_only_distributed_expenses_and_partial_funded_amount(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Expense::factory()->distributed()->create([
            'amount' => 500000,
            'funded_amount' => 0,
            'expense_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(7)->toDateString(),
        ]);

        Expense::factory()->distributed()->create([
            'amount' => 300000,
            'funded_amount' => 100000,
            'expense_date' => now()->subDays(15)->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        Expense::factory()->registered()->create([
            'amount' => 999999,
            'funded_amount' => 0,
            'expense_date' => now()->subDays(25)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
        ]);

        $this->actingAs($admin);

        $stats = $this->invokeProtectedMethod(new OverduePortfolioStatWidget, 'getStats');

        $this->assertCount(1, $stats);
        $this->assertSame('700000', preg_replace('/\D/', '', (string) $stats[0]->getValue()));
    }

    public function test_admin_collections_summary_widget_calculates_monto_en_caja_excluding_cancelled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = Propietario::factory()->create();

        Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 450000,
            'applied_amount' => 300000,
            'unapplied_amount' => 150000,
            'status' => CollectionStatus::PartiallyApplied->value,
        ]);

        Collection::factory()->cancelled()->create([
            'propietario_id' => $owner->id,
            'amount' => 999999,
            'applied_amount' => 0,
            'unapplied_amount' => 999999,
        ]);

        ExpenseFundingPayment::factory()->create([
            'amount' => 100000,
            'is_void' => false,
        ]);

        ExpenseFundingPayment::factory()->create([
            'amount' => 150000,
            'is_void' => false,
        ]);

        ExpenseFundingPayment::factory()->voided()->create([
            'amount' => 80000,
        ]);

        $this->actingAs($admin);

        $stats = $this->invokeProtectedMethod(new CashOnHandStatWidget, 'getStats');

        $this->assertCount(1, $stats);
        $this->assertSame('200000', preg_replace('/\D/', '', (string) $stats[0]->getValue()));
    }

    public function test_collections_trend_chart_returns_six_month_series(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $owner = Propietario::factory()->create();

        $currentMonthStart = now()->startOfMonth();
        $emitMonthDate = $currentMonthStart->copy()->subMonthsNoOverflow(2)->addDays(3);
        $paymentMonthDate = $currentMonthStart->copy()->subMonthsNoOverflow(1)->addDays(4);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 125000,
            'status' => ChargeStatus::Pending->value,
            'due_date' => $emitMonthDate->toDateString(),
        ]);

        DB::table('partner_charges')
            ->where('id', $charge->id)
            ->update([
                'created_at' => now()->startOfMonth()->toDateTimeString(),
                'updated_at' => now()->startOfMonth()->toDateTimeString(),
            ]);

        $payment = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 125000,
            'applied_amount' => 0,
            'unapplied_amount' => 125000,
            'status' => CollectionStatus::PartiallyApplied->value,
            'collection_date' => $paymentMonthDate->toDateString(),
        ]);

        $allocation = CollectionAllocation::query()->create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => 40000,
            'allocated_at' => now()->toDateTimeString(),
            'created_by' => $superAdmin->id,
        ]);

        DB::table('collection_allocations')
            ->where('id', $allocation->id)
            ->update([
                'allocated_at' => now()->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

        $this->actingAs($superAdmin);

        $data = $this->invokeProtectedMethod(new CollectionsTrendChartWidget, 'getData');

        $this->assertCount(6, $data['labels']);
        $this->assertCount(6, $data['datasets'][0]['data']);
        $this->assertCount(6, $data['datasets'][1]['data']);
        $this->assertContains(125000.0, $data['datasets'][0]['data']);
        $this->assertContains(40000.0, $data['datasets'][1]['data']);
    }

    public function test_morosidad_uses_active_owners_with_active_sold_lots_only(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $morosoOwner = Propietario::factory()->create();
        $alDiaOwner = Propietario::factory()->create();
        $inactiveOwner = Propietario::factory()->create();

        $this->attachActiveSoldLot($morosoOwner);
        $this->attachActiveSoldLot($alDiaOwner);
        $this->attachInactiveSoldLot($inactiveOwner);

        $expense = Expense::factory()->distributed()->create([
            'amount' => 100000,
            'funded_amount' => 0,
            'expense_date' => now()->subDays(15)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $morosoOwner->id,
            'expense_id' => $expense->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending->value,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($admin);

        $onTimeStats = $this->invokeProtectedMethod(new OwnersOnTimeStatWidget, 'getStats');
        $delinquentStats = $this->invokeProtectedMethod(new DelinquentOwnersStatWidget, 'getStats');

        $this->assertSame('1', preg_replace('/\D/', '', (string) $onTimeStats[0]->getValue()));
        $this->assertSame('1', preg_replace('/\D/', '', (string) $delinquentStats[0]->getValue()));
    }

    public function test_top_delinquent_owners_widget_returns_sorted_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $ownerA = Propietario::factory()->create(['nombre' => 'Ana', 'apellido' => 'Perez']);
        $ownerB = Propietario::factory()->create(['nombre' => 'Beto', 'apellido' => 'Gomez']);

        PartnerCharge::factory()->create([
            'propietario_id' => $ownerA->id,
            'amount' => 90000,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending->value,
            'due_date' => now()->subDays(20)->toDateString(),
        ]);

        PartnerCharge::factory()->create([
            'propietario_id' => $ownerB->id,
            'amount' => 150000,
            'paid_amount' => 0,
            'status' => ChargeStatus::Pending->value,
            'due_date' => now()->subDays(35)->toDateString(),
        ]);

        $this->actingAs($admin);

        $data = $this->invokeProtectedMethod(new TopDelinquentOwnersWidget, 'getViewData');

        $this->assertNotEmpty($data['rows']);
        $this->assertSame('Beto Gomez', $data['rows'][0]['propietario_nombre']);
        $this->assertSame(150000.0, $data['rows'][0]['overdue_total']);
    }

    public function test_latest_collections_table_widget_returns_last_seven_collections(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = Propietario::factory()->create();

        Collection::factory()->count(9)->create([
            'propietario_id' => $owner->id,
            'collection_date' => now()->subDays(20)->toDateString(),
        ]);

        $latest = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'collection_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin);

        $data = $this->invokeProtectedMethod(new LatestCollectionsTableWidget, 'getViewData');

        $this->assertCount(7, $data['rows']);
        $this->assertSame($latest->id, $data['rows'][0]['id']);
    }

    public function test_admin_widgets_are_hidden_for_owner_role(): void
    {
        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('propietario');

        $this->actingAs($ownerUser);

        $this->assertFalse(OverduePortfolioStatWidget::canView());
        $this->assertFalse(OwnersOnTimeStatWidget::canView());
        $this->assertFalse(DelinquentOwnersStatWidget::canView());
        $this->assertFalse(CashOnHandStatWidget::canView());
        $this->assertFalse(PendingPortfolioStatWidget::canView());
        $this->assertFalse(LotsSummaryStatWidget::canView());
        $this->assertFalse(LatestCollectionsTableWidget::canView());
        $this->assertFalse(CollectionsTrendChartWidget::canView());
        $this->assertFalse(TopDelinquentOwnersWidget::canView());
    }

    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function attachActiveSoldLot(Propietario $owner): void
    {
        $etapa = Etapa::factory()->create();
        $lot = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'estado' => 'vendido',
        ]);

        $owner->lotes()->attach($lot->id, [
            'status' => 'active',
            'assigned_at' => now(),
        ]);
    }

    private function attachInactiveSoldLot(Propietario $owner): void
    {
        $etapa = Etapa::factory()->create();
        $lot = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'estado' => 'vendido',
        ]);

        $owner->lotes()->attach($lot->id, [
            'status' => 'inactive',
            'assigned_at' => now()->subDays(10),
            'unassigned_at' => now()->subDay(),
        ]);
    }
}
