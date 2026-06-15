<?php

namespace Tests\Unit\Models;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use App\Models\Collection;
use App\Models\Propietario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_cast_to_enum(): void
    {
        $payment = Collection::factory()->pendingApplication()->create();

        $this->assertSame(CollectionStatus::PendingApplication, $payment->status);
    }

    public function test_remaining_amount_accessor_returns_amount_minus_applied_amount(): void
    {
        $payment = Collection::factory()->create([
            'amount' => 100000,
            'applied_amount' => 30000,
            'unapplied_amount' => 70000,
        ]);

        $this->assertSame(70000.0, $payment->remaining_amount);
    }

    public function test_payment_rejects_applied_amount_greater_than_total_amount(): void
    {
        $this->expectException(ValidationException::class);

        Collection::query()->create([
            'propietario_id' => Propietario::factory()->create()->id,
            'amount' => 1000,
            'applied_amount' => 1200,
            'unapplied_amount' => 0,
            'collection_date' => now()->toDateString(),
            'collection_method' => CollectionMethod::Efectivo->value,
            'status' => CollectionStatus::PendingApplication->value,
        ]);
    }

    public function test_fully_applied_payment_only_allows_status_change_to_cancelled(): void
    {
        $payment = Collection::factory()->fullyApplied()->create();

        $this->expectException(DomainException::class);

        $payment->update([
            'reference' => 'REF-EDITADA',
        ]);
    }

    public function test_cancelled_payment_cannot_be_edited(): void
    {
        $payment = Collection::factory()->cancelled()->create();

        $this->expectException(DomainException::class);

        $payment->update([
            'notes' => 'Intento de edición',
        ]);
    }

    public function test_fully_applied_payment_can_change_status_to_cancelled(): void
    {
        $payment = Collection::factory()->fullyApplied()->create();

        $payment->update([
            'status' => CollectionStatus::Cancelled,
        ]);

        $this->assertSame(CollectionStatus::Cancelled, $payment->fresh()->status);
    }
}
