<?php

namespace App\Models;

use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use Database\Factories\PartnerChargeFactory;
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

class PartnerCharge extends Model
{
    /** @use HasFactory<PartnerChargeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'propietario_id',
        'expense_id',
        'amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'due_date',
        'description',
        'calculation_type',
        'partner_hectares_at_moment',
        'total_hectares_at_moment',
        'percentage_applied',
        'calculation_notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'paid_amount' => 0,
        'remaining_amount' => 0,
        'status' => ChargeStatus::Pending,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'partner_hectares_at_moment' => 'decimal:4',
            'total_hectares_at_moment' => 'decimal:4',
            'percentage_applied' => 'decimal:2',
            'status' => ChargeStatus::class,
            'due_date' => 'date',
            'calculation_type' => ExpenseDistributionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $charge): void {
            $charge->remaining_amount = round((float) $charge->amount - (float) $charge->paid_amount, 2);

            $validator = Validator::make($charge->attributesToArray(), [
                'propietario_id' => ['required', 'exists:propietarios,id'],
                'expense_id' => ['required', 'exists:expenses,id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'paid_amount' => ['required', 'numeric', 'gte:0', 'lte:amount'],
                'remaining_amount' => ['required', 'numeric', 'gte:0'],
                'status' => ['required', Rule::in(array_column(ChargeStatus::cases(), 'value'))],
                'due_date' => ['nullable', 'date'],
                'description' => ['nullable', 'string'],
                'calculation_type' => ['required', Rule::in(array_column(ExpenseDistributionType::cases(), 'value'))],
                'partner_hectares_at_moment' => ['nullable', 'numeric', 'gte:0'],
                'total_hectares_at_moment' => ['nullable', 'numeric', 'gte:0'],
                'percentage_applied' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
                'calculation_notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            if (! $charge->exists) {
                return;
            }

            /** @var self $original */
            $original = self::query()->with('expense')->findOrFail($charge->id);

            $closingService = app(MonthlyClosingService::class);

            if ($closingService->isDateInClosedPeriod($original->created_at)) {
                $dirty = array_keys($charge->getDirty());
                $allowedForCollectionApplication = [
                    'paid_amount',
                    'remaining_amount',
                    'status',
                    'updated_at',
                ];

                $blockedAttributes = array_diff($dirty, $allowedForCollectionApplication);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se pueden editar campos estructurales de un cobro en período cerrado.');
                }
            }

            if ($original->expense->status !== ExpenseStatus::Registered) {
                $dirty = array_keys($charge->getDirty());

                // Campos que SÍ pueden actualizarse al aplicar pagos
                $allowedForCollectionApplication = [
                    'paid_amount',
                    'remaining_amount',
                    'status',
                    'updated_at',
                ];

                $blockedAttributes = array_diff($dirty, $allowedForCollectionApplication);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se puede editar un cobro de un gasto ya distribuido o cancelado.');
                }
            }
        });
    }

    protected function remainingAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->amount - (float) $this->paid_amount, 2),
        );
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CollectionAllocation::class);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ChargeStatus::Pending->value);
    }

    #[Scope]
    protected function partial(Builder $query): void
    {
        $query->where('status', ChargeStatus::Partial->value);
    }

    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('status', ChargeStatus::Paid->value);
    }

    #[Scope]
    protected function unpaid(Builder $query): void
    {
        $query->whereIn('status', [
            ChargeStatus::Pending->value,
            ChargeStatus::Partial->value,
        ]);
    }

    #[Scope]
    protected function byExpense(Builder $query, int $expenseId): void
    {
        $query->where('expense_id', $expenseId);
    }

    #[Scope]
    protected function byPropietario(Builder $query, int $propietarioId): void
    {
        $query->where('propietario_id', $propietarioId);
    }
}
