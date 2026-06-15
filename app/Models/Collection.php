<?php

namespace App\Models;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Enums\CollectionStatus;
use Database\Factories\CollectionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'propietario_id',
        'amount',
        'applied_amount',
        'unapplied_amount',
        'collection_date',
        'collection_method',
        'reference',
        'notes',
        'status',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'applied_amount' => 0,
        'unapplied_amount' => 0,
        'status' => CollectionStatus::PendingApplication->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'unapplied_amount' => 'decimal:2',
            'collection_date' => 'date',
            'collection_method' => CollectionMethod::class,
            'status' => CollectionStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $payment->unapplied_amount = round((float) $payment->amount - (float) $payment->applied_amount, 2);

            $validator = Validator::make($payment->attributesToArray(), [
                'propietario_id' => ['required', 'exists:propietarios,id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'applied_amount' => ['required', 'numeric', 'gte:0', 'lte:amount'],
                'unapplied_amount' => ['required', 'numeric', 'gte:0'],
                'collection_date' => ['required', 'date', 'before_or_equal:today'],
                'collection_method' => ['required', Rule::in(array_column(CollectionMethod::cases(), 'value'))],
                'reference' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
                'status' => ['required', Rule::in(array_column(CollectionStatus::cases(), 'value'))],
                'created_by' => ['nullable', 'exists:users,id'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $closingService = app(MonthlyClosingService::class);

            if ($closingService->isDateInClosedPeriod($payment->collection_date)) {
                throw new DomainException('No se pueden registrar ni editar pagos en un período contable cerrado.');
            }

            if (! $payment->exists) {
                return;
            }

            /** @var self $original */
            $original = self::query()->findOrFail($payment->id);

            if ($original->status === CollectionStatus::Cancelled) {
                $dirty = array_keys($payment->getDirty());
                $blockedAttributes = array_diff($dirty, ['updated_at']);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se puede editar un pago cancelado.');
                }

                return;
            }

            if ($original->status === CollectionStatus::FullyApplied) {
                $dirty = array_keys($payment->getDirty());
                $allowed = ['status', 'updated_at'];
                $blockedAttributes = array_diff($dirty, $allowed);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se puede editar un pago totalmente aplicado.');
                }

                if ($payment->isDirty('status') && $payment->status !== CollectionStatus::Cancelled) {
                    throw new DomainException('Un pago totalmente aplicado solo puede cambiar a cancelado.');
                }
            }
        });
    }

    protected function remainingAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->amount - (float) $this->applied_amount, 2),
        );
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CollectionAllocation::class);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', CollectionStatus::PendingApplication->value);
    }

    #[Scope]
    protected function partiallyApplied(Builder $query): void
    {
        $query->where('status', CollectionStatus::PartiallyApplied->value);
    }

    #[Scope]
    protected function fullyApplied(Builder $query): void
    {
        $query->where('status', CollectionStatus::FullyApplied->value);
    }

    #[Scope]
    protected function byPropietario(Builder $query, int $propietarioId): void
    {
        $query->where('propietario_id', $propietarioId);
    }
}
