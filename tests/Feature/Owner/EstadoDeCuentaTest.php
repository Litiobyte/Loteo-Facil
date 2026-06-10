<?php

namespace Tests\Feature\Owner;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Pages\EstadoDeCuenta;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EstadoDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
    }

    public function test_page_returns_null_statement_when_user_has_no_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertNull($data['statement']);
    }

    public function test_page_returns_statement_for_authenticated_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->create(['propietario_id' => $owner->id]);
        Payment::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertNotNull($data['statement']);
        $this->assertSame($owner->id, $data['statement']['owner']['id']);
    }

    public function test_debt_by_category_only_includes_positive_remaining_amounts(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'remaining_amount' => 50000,
            'status' => ChargeStatus::Pending->value,
        ]);
        PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'remaining_amount' => 0,
            'status' => ChargeStatus::Paid->value,
        ]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertGreaterThan(0, $data['debtByCategory']->sum());
    }

    public function test_pending_charges_collection_only_contains_pending_and_partial(): void
    {
        [$user, $owner] = $this->createOwner();

        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Pending->value]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Partial->value]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'status' => ChargeStatus::Paid->value]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertCount(2, $data['pendingCharges']);
    }

    public function test_quick_filters_set_expected_date_ranges(): void
    {
        $page = new EstadoDeCuenta;

        $page->setQuickFilter('this_month');
        $this->assertNotNull($page->desde);
        $this->assertNotNull($page->hasta);

        $page->setQuickFilter('all');
        $this->assertNull($page->desde);
        $this->assertNull($page->hasta);
    }

    public function test_month_comparison_contains_three_months(): void
    {
        [$user] = $this->createOwner();
        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $page->mount();

        $this->assertCount(3, $page->monthComparison);
    }

    public function test_timeline_is_limited_to_twenty_items(): void
    {
        [$user, $owner] = $this->createOwner();
        PartnerCharge::factory()->count(25)->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertCount(20, $data['timeline']);
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
