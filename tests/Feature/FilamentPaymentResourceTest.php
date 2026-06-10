<?php

namespace Tests\Feature;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Filament\Admin\Resources\PaymentResource;
use App\Models\PartnerCharge;
use App\Models\Payment;
use App\Models\Propietario;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentPaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_can_list_payments(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Payment::factory()->count(3)->create();

        $this->actingAs($admin);

        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertSame(3, Payment::query()->count());
        $this->assertContains(PaymentResource::class, app(PanelRegistry::class)->get('admin')->getResources());
    }

    public function test_can_create_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $propietario = Propietario::factory()->create();

        $this->actingAs($admin);

        $payment = Payment::query()->create([
            'propietario_id' => $propietario->id,
            'amount' => 125000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'transferencia',
            'reference' => 'TRX-1234',
            'notes' => 'Pago inicial de prueba',
            'status' => PaymentStatus::PendingApplication,
            'applied_amount' => 0,
            'unapplied_amount' => 125000,
            'created_by' => $admin->id,
        ]);

        $this->assertModelExists($payment);
        $this->assertSame('125000.00', $payment->amount);
        $this->assertSame(PaymentStatus::PendingApplication, $payment->status);
    }

    public function test_can_edit_payment_only_if_not_fully_applied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $editable = Payment::factory()->pendingApplication()->create();
        $blocked = Payment::factory()->fullyApplied()->create();

        $this->actingAs($admin);

        $this->assertTrue(PaymentResource::canEdit($editable));
        $this->assertFalse(PaymentResource::canEdit($blocked));
    }

    public function test_cannot_edit_fully_applied_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payment = Payment::factory()->fullyApplied()->create();

        $this->actingAs($admin);

        $this->assertFalse(PaymentResource::canEdit($payment));
    }

    public function test_can_view_payment_details(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $payment = Payment::factory()->create();

        $this->actingAs($admin);

        $this->assertTrue(PaymentResource::canView($payment));
    }

    public function test_can_apply_payment_automatically(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $propietario = Propietario::factory()->create();

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 100000,
            'applied_amount' => 0,
            'unapplied_amount' => 100000,
            'status' => PaymentStatus::PendingApplication,
        ]);

        $this->actingAs($admin);

        $createdCount = PaymentResource::applyAutomatically($payment);

        $this->assertSame(1, $createdCount);

        $payment->refresh();
        $charge->refresh();

        $this->assertSame(PaymentStatus::FullyApplied, $payment->status);
        $this->assertSame(0.0, (float) $payment->unapplied_amount);
        $this->assertSame(0.0, (float) $charge->remaining_amount);
    }

    public function test_can_apply_payment_manually(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $propietario = Propietario::factory()->create();

        $chargeA = PartnerCharge::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 50000,
            'paid_amount' => 0,
            'remaining_amount' => 50000,
        ]);

        $chargeB = PartnerCharge::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 70000,
            'paid_amount' => 0,
            'remaining_amount' => 70000,
        ]);

        $payment = Payment::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 90000,
            'applied_amount' => 0,
            'unapplied_amount' => 90000,
            'status' => PaymentStatus::PendingApplication,
        ]);

        $this->actingAs($admin);

        $createdCount = PaymentResource::applyManually($payment, [
            ['charge_id' => $chargeA->id, 'amount' => 50000],
            ['charge_id' => $chargeB->id, 'amount' => 40000],
        ]);

        $this->assertSame(2, $createdCount);

        $payment->refresh();
        $chargeA->refresh();
        $chargeB->refresh();

        $this->assertSame(PaymentStatus::FullyApplied, $payment->status);
        $this->assertSame(0.0, (float) $payment->unapplied_amount);
        $this->assertSame(0.0, (float) $chargeA->remaining_amount);
        $this->assertSame(30000.0, (float) $chargeB->remaining_amount);
    }

    public function test_can_cancel_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $payment = Payment::factory()->pendingApplication()->create();

        $this->actingAs($admin);

        PaymentResource::cancelPayment($payment);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
    }

    public function test_payment_resource_authorization_only_allows_admin_and_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $owner->assignRole('propietario');

        $this->actingAs($superAdmin);
        $this->assertTrue(PaymentResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(PaymentResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(PaymentResource::canAccess());
    }
}
