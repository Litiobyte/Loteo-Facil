<?php

namespace Tests\Feature\Owner;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Widgets\FinancialSummaryWidget;
use App\Filament\Owner\Widgets\NextCollectionEstimateWidget;
use App\Filament\Owner\Widgets\OverdueAlertsWidget;
use App\Filament\Owner\Widgets\WelcomeWidget;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
    }

    public function test_financial_summary_widget_returns_placeholder_without_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $stats = $this->invokeProtectedMethod(new FinancialSummaryWidget, 'getStats');

        $this->assertCount(1, $stats);
    }

    public function test_financial_summary_widget_uses_balance_values_for_owner(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => ChargeStatus::Pending->value,
        ]);

        $this->actingAs($user);

        $stats = $this->invokeProtectedMethod(new FinancialSummaryWidget, 'getStats');

        $this->assertCount(4, $stats);
    }

    public function test_welcome_widget_lists_active_lot_codes_and_hectares(): void
    {
        [$user, $owner] = $this->createOwner();

        $lotA = Lote::factory()->create(['codigo' => 'LT-001', 'metros_cuadrados' => 10000]);
        $lotB = Lote::factory()->create(['codigo' => 'LT-002', 'metros_cuadrados' => 5000]);
        $owner->lotes()->attach($lotA->id, ['status' => 'active', 'assigned_at' => now()]);
        $owner->lotes()->attach($lotB->id, ['status' => 'active', 'assigned_at' => now()]);

        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new WelcomeWidget, 'getViewData');

        $this->assertTrue($data['hasPropietario']);
        $this->assertSame(['LT-001', 'LT-002'], $data['loteCodes']);
        $this->assertSame(1.5, $data['totalHectares']);
    }

    public function test_welcome_widget_handles_user_without_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new WelcomeWidget, 'getViewData');

        $this->assertFalse($data['hasPropietario']);
    }

    public function test_overdue_widget_hidden_when_no_overdue_charges(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new OverdueAlertsWidget, 'getViewData');

        $this->assertFalse($data['hasAlert']);
    }

    public function test_overdue_widget_shows_count_total_and_oldest_due_date(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Pending->value,
            'amount' => 100000,
            'paid_amount' => 0,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Partial->value,
            'amount' => 100000,
            'paid_amount' => 50000,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new OverdueAlertsWidget, 'getViewData');

        $this->assertTrue($data['hasAlert']);
        $this->assertSame(2, $data['count']);
        $this->assertSame(150000.0, $data['totalAmount']);
    }

    public function test_next_payment_widget_empty_when_next_month_has_no_charges(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new NextCollectionEstimateWidget, 'getViewData');

        $this->assertFalse($data['hasCharges']);
    }

    public function test_next_payment_widget_lists_only_next_month_unpaid_charges(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->count(2)->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Pending->value,
            'amount' => 25000,
            'paid_amount' => 0,
            'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString(),
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Paid->value,
            'remaining_amount' => 0,
            'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(8)->toDateString(),
        ]);

        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new NextCollectionEstimateWidget, 'getViewData');

        $this->assertTrue($data['hasCharges']);
        $this->assertCount(2, $data['charges']);
        $this->assertSame(50000.0, $data['totalAmount']);
    }

    public function test_next_payment_widget_limits_list_and_returns_remaining_count(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->count(7)->create([
            'propietario_id' => $owner->id,
            'status' => ChargeStatus::Pending->value,
            'amount' => 10000,
            'paid_amount' => 0,
            'due_date' => now()->addMonthNoOverflow()->startOfMonth()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($user);

        $data = $this->invokeProtectedMethod(new NextCollectionEstimateWidget, 'getViewData');

        $this->assertCount(5, $data['charges']);
        $this->assertSame(2, $data['remainingCount']);
    }

    public function test_widgets_without_propietario_return_empty_states(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $overdue = $this->invokeProtectedMethod(new OverdueAlertsWidget, 'getViewData');
        $next = $this->invokeProtectedMethod(new NextCollectionEstimateWidget, 'getViewData');

        $this->assertFalse($overdue['hasAlert']);
        $this->assertFalse($next['hasCharges']);
    }

    /**
     * @return array{0: User, 1: Propietario}
     */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $user->id]);

        return [$user, $owner];
    }

    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
