<?php

namespace Tests\Feature\Owner;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Filament\Owner\Resources\Pagos\MisPagosResource;
use App\Models\Payment;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MisPagosResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
    }

    public function test_scope_only_returns_authenticated_owner_payments(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        Payment::factory()->count(2)->create(['propietario_id' => $owner->id]);
        Payment::factory()->count(3)->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertSame(2, MisPagosResource::getEloquentQuery()->count());
    }

    public function test_scope_returns_empty_query_for_user_without_propietario(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user);

        $this->assertSame(0, MisPagosResource::getEloquentQuery()->count());
    }

    public function test_resource_is_read_only_for_owner(): void
    {
        [$user, $owner] = $this->createOwner();
        $payment = Payment::factory()->create(['propietario_id' => $owner->id]);

        $this->actingAs($user);

        $this->assertFalse(MisPagosResource::canCreate());
        $this->assertFalse(MisPagosResource::canEdit($payment));
        $this->assertFalse(MisPagosResource::canDelete($payment));
    }

    public function test_can_view_only_own_payment(): void
    {
        [$user, $owner] = $this->createOwner();
        [, $otherOwner] = $this->createOwner();

        $own = Payment::factory()->create(['propietario_id' => $owner->id]);
        $other = Payment::factory()->create(['propietario_id' => $otherOwner->id]);

        $this->actingAs($user);

        $this->assertTrue(MisPagosResource::canView($own));
        $this->assertFalse(MisPagosResource::canView($other));
    }

    public function test_payment_method_label_mapping_is_correct(): void
    {
        $this->assertSame('Efectivo', MisPagosResource::paymentMethodLabel(PaymentMethod::Efectivo));
        $this->assertSame('Transferencia', MisPagosResource::paymentMethodLabel(PaymentMethod::Transferencia));
        $this->assertSame('Cheque', MisPagosResource::paymentMethodLabel(PaymentMethod::Cheque));
        $this->assertSame('Otro', MisPagosResource::paymentMethodLabel(PaymentMethod::Otro));
    }

    public function test_status_label_mapping_is_correct(): void
    {
        $this->assertSame('Pendiente aplicación', MisPagosResource::statusLabel(PaymentStatus::PendingApplication));
        $this->assertSame('Parcialmente aplicado', MisPagosResource::statusLabel(PaymentStatus::PartiallyApplied));
        $this->assertSame('Totalmente aplicado', MisPagosResource::statusLabel(PaymentStatus::FullyApplied));
        $this->assertSame('Cancelado', MisPagosResource::statusLabel(PaymentStatus::Cancelled));
    }

    public function test_status_color_mapping_is_correct(): void
    {
        $this->assertSame('gray', MisPagosResource::statusColor(PaymentStatus::PendingApplication));
        $this->assertSame('warning', MisPagosResource::statusColor(PaymentStatus::PartiallyApplied));
        $this->assertSame('success', MisPagosResource::statusColor(PaymentStatus::FullyApplied));
        $this->assertSame('danger', MisPagosResource::statusColor(PaymentStatus::Cancelled));
    }

    public function test_quick_filter_conditions_last_month_and_year_can_be_applied(): void
    {
        [$user, $owner] = $this->createOwner();

        Payment::factory()->create(['propietario_id' => $owner->id, 'payment_date' => now()->subDays(15)->toDateString()]);
        Payment::factory()->create(['propietario_id' => $owner->id, 'payment_date' => now()->subYears(2)->toDateString()]);

        $this->actingAs($user);

        $lastMonth = MisPagosResource::getEloquentQuery()
            ->whereDate('payment_date', '>=', now()->subMonthNoOverflow()->startOfDay()->toDateString())
            ->count();
        $thisYear = MisPagosResource::getEloquentQuery()
            ->whereYear('payment_date', now()->year)
            ->count();

        $this->assertSame(1, $lastMonth);
        $this->assertSame(1, $thisYear);
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
}
