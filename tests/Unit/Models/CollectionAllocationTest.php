<?php

namespace Tests\Unit\Models;

use App\Models\Collection;
use App\Models\CollectionAllocation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_allocation_when_payment_and_charge_have_different_propietario(): void
    {
        $paymentOwner = Propietario::factory()->create();
        $chargeOwner = Propietario::factory()->create();

        $payment = Collection::factory()->create([
            'propietario_id' => $paymentOwner->id,
            'amount' => 90000,
            'applied_amount' => 0,
            'unapplied_amount' => 90000,
        ]);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $chargeOwner->id,
            'amount' => 50000,
            'paid_amount' => 0,
            'remaining_amount' => 50000,
        ]);

        $this->expectException(DomainException::class);

        CollectionAllocation::query()->create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => 10000,
            'allocated_at' => now(),
        ]);
    }

    public function test_rejects_allocation_that_exceeds_payment_unapplied_amount(): void
    {
        $owner = Propietario::factory()->create();

        $payment = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 50000,
            'applied_amount' => 45000,
            'unapplied_amount' => 5000,
        ]);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
        ]);

        $this->expectException(DomainException::class);

        CollectionAllocation::query()->create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => 6000,
            'allocated_at' => now(),
        ]);
    }

    public function test_rejects_allocation_that_exceeds_charge_remaining_amount(): void
    {
        $owner = Propietario::factory()->create();

        $payment = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 90000,
            'applied_amount' => 0,
            'unapplied_amount' => 90000,
        ]);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 40000,
            'paid_amount' => 30000,
            'remaining_amount' => 10000,
        ]);

        $this->expectException(DomainException::class);

        CollectionAllocation::query()->create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => 15000,
            'allocated_at' => now(),
        ]);
    }

    public function test_valid_allocation_can_be_created(): void
    {
        $owner = Propietario::factory()->create();

        $payment = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 60000,
            'applied_amount' => 0,
            'unapplied_amount' => 60000,
        ]);

        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 60000,
            'paid_amount' => 0,
            'remaining_amount' => 60000,
        ]);

        $allocation = CollectionAllocation::query()->create([
            'collection_id' => $payment->id,
            'partner_charge_id' => $charge->id,
            'amount' => 30000,
            'allocated_at' => now(),
        ]);

        $this->assertModelExists($allocation);
    }
}
