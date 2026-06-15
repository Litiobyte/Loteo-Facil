<?php

namespace Tests\Feature;

use App\Domain\Collections\Enums\CollectionStatus;
use App\Filament\Admin\Resources\CollectionResource;
use App\Models\Collection;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentCollectionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_can_list_collections(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Collection::factory()->count(3)->create();

        $this->actingAs($admin);

        $this->assertTrue(CollectionResource::canViewAny());
        $this->assertSame(3, Collection::query()->count());
        $this->assertContains(CollectionResource::class, app(PanelRegistry::class)->get('admin')->getResources());
    }

    public function test_can_create_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $propietario = Propietario::factory()->create();

        $this->actingAs($admin);

        $payment = Collection::query()->create([
            'propietario_id' => $propietario->id,
            'amount' => 125000,
            'collection_date' => now()->toDateString(),
            'collection_method' => 'transferencia',
            'reference' => 'TRX-1234',
            'notes' => 'Recaudacion inicial de prueba',
            'status' => CollectionStatus::PendingApplication,
            'applied_amount' => 0,
            'unapplied_amount' => 125000,
            'created_by' => $admin->id,
        ]);

        $this->assertModelExists($payment);
        $this->assertSame('125000.00', $payment->amount);
        $this->assertSame(CollectionStatus::PendingApplication, $payment->status);
    }

    public function test_can_edit_payment_only_if_not_fully_applied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $editable = Collection::factory()->pendingApplication()->create();
        $blocked = Collection::factory()->fullyApplied()->create();

        $this->actingAs($admin);

        $this->assertTrue(CollectionResource::canEdit($editable));
        $this->assertFalse(CollectionResource::canEdit($blocked));
    }

    public function test_cannot_edit_fully_applied_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payment = Collection::factory()->fullyApplied()->create();

        $this->actingAs($admin);

        $this->assertFalse(CollectionResource::canEdit($payment));
    }

    public function test_can_view_payment_details(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $payment = Collection::factory()->create();

        $this->actingAs($admin);

        $this->assertTrue(CollectionResource::canView($payment));
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

        $payment = Collection::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 100000,
            'applied_amount' => 0,
            'unapplied_amount' => 100000,
            'status' => CollectionStatus::PendingApplication,
        ]);

        $this->actingAs($admin);

        $createdCount = CollectionResource::applyAutomatically($payment);

        $this->assertSame(1, $createdCount);

        $payment->refresh();
        $charge->refresh();

        $this->assertSame(CollectionStatus::FullyApplied, $payment->status);
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

        $payment = Collection::factory()->create([
            'propietario_id' => $propietario->id,
            'amount' => 90000,
            'applied_amount' => 0,
            'unapplied_amount' => 90000,
            'status' => CollectionStatus::PendingApplication,
        ]);

        $this->actingAs($admin);

        $createdCount = CollectionResource::applyManually($payment, [
            ['charge_id' => $chargeA->id, 'amount' => 50000],
            ['charge_id' => $chargeB->id, 'amount' => 40000],
        ]);

        $this->assertSame(2, $createdCount);

        $payment->refresh();
        $chargeA->refresh();
        $chargeB->refresh();

        $this->assertSame(CollectionStatus::FullyApplied, $payment->status);
        $this->assertSame(0.0, (float) $payment->unapplied_amount);
        $this->assertSame(0.0, (float) $chargeA->remaining_amount);
        $this->assertSame(30000.0, (float) $chargeB->remaining_amount);
    }

    public function test_can_cancel_payment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $payment = Collection::factory()->pendingApplication()->create();

        $this->actingAs($admin);

        CollectionResource::cancelCollection($payment);

        $payment->refresh();

        $this->assertSame(CollectionStatus::Cancelled, $payment->status);
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
        $this->assertTrue(CollectionResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(CollectionResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(CollectionResource::canAccess());
    }
}
