<?php

namespace App\Models;

use App\Domain\Accounting\Services\MonthlyClosingService;
use Database\Factories\PaymentAllocationFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'partner_charge_id',
        'amount',
        'allocated_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            $validator = Validator::make($allocation->attributesToArray(), [
                'payment_id' => ['required', 'exists:payments,id'],
                'partner_charge_id' => ['required', 'exists:partner_charges,id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'allocated_at' => ['required', 'date'],
                'created_by' => ['nullable', 'exists:users,id'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payment = $allocation->relationLoaded('payment')
                ? $allocation->payment
                : Payment::query()->findOrFail($allocation->payment_id);

            $charge = $allocation->relationLoaded('charge')
                ? $allocation->charge
                : PartnerCharge::query()->findOrFail($allocation->partner_charge_id);

            if ($payment->propietario_id !== $charge->propietario_id) {
                throw new DomainException('El pago y el cobro deben pertenecer al mismo propietario.');
            }

            if ((float) $allocation->amount > (float) $payment->unapplied_amount) {
                throw new DomainException('El monto aplicado no puede exceder el saldo no aplicado del pago.');
            }

            if ((float) $allocation->amount > (float) $charge->remaining_amount) {
                throw new DomainException('El monto aplicado no puede exceder el saldo pendiente del cobro.');
            }

            $closingService = app(MonthlyClosingService::class);

            if ($closingService->isDateInClosedPeriod($allocation->allocated_at)) {
                throw new DomainException('No se pueden registrar asignaciones en un período contable cerrado.');
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PartnerCharge::class, 'partner_charge_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
