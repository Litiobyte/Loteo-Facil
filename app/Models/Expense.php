<?php

namespace App\Models;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use Database\Factories\ExpenseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $expense_category_id
 * @property string $title
 * @property string|null $description
 * @property string $amount
 * @property Carbon $expense_date
 * @property Carbon|null $due_date
 * @property ExpenseDistributionType $distribution_type
 * @property ExpenseStatus $status
 * @property string $funded_amount
 * @property Carbon|null $distributed_at
 * @property Carbon|null $paid_at
 * @property int|null $created_by
 * @property string|null $notes
 * @property bool $is_recurring
 * @property Carbon|null $recurrence_end_date
 * @property int|null $parent_expense_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ExpenseStatus::Registered,
        'funded_amount' => 0,
        'is_recurring' => false,
        'historical_distribution_blocked' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'expense_category_id',
        'supplier_id',
        'title',
        'description',
        'amount',
        'expense_date',
        'due_date',
        'distribution_type',
        'status',
        'funded_amount',
        'distributed_at',
        'paid_at',
        'created_by',
        'notes',
        'is_recurring',
        'recurrence_end_date',
        'parent_expense_id',
        'historical_distribution_blocked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'due_date' => 'date',
            'distribution_type' => ExpenseDistributionType::class,
            'status' => ExpenseStatus::class,
            'funded_amount' => 'decimal:2',
            'distributed_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_recurring' => 'boolean',
            'historical_distribution_blocked' => 'boolean',
            'recurrence_end_date' => 'date',
        ];
    }

    /**
     * Las fechas futuras solo se permiten en instancias con una cabeza asociada.
     */
    protected static function booted(): void
    {
        static::saving(function (self $expense): void {
            $validator = Validator::make($expense->attributesToArray(), [
                'expense_category_id' => ['required', 'exists:expense_categories,id'],
                'supplier_id' => ['nullable', 'exists:suppliers,id'],
                'title' => ['required', 'string', 'max:200'],
                'description' => ['nullable', 'string', 'max:500'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'expense_date' => array_filter(['required', 'date', $expense->parent_expense_id === null ? 'before_or_equal:today' : null]),
                'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
                'distribution_type' => [
                    'required',
                    Rule::in(array_column(ExpenseDistributionType::cases(), 'value')),
                ],
                'status' => [
                    'required',
                    Rule::in(array_column(ExpenseStatus::cases(), 'value')),
                ],
                'funded_amount' => ['required', 'numeric', 'gte:0'],
                'distributed_at' => ['nullable', 'date'],
                'paid_at' => ['nullable', 'date'],
                'created_by' => ['nullable', 'exists:users,id'],
                'notes' => ['nullable', 'string'],
                'is_recurring' => ['boolean'],
                'recurrence_end_date' => ['nullable', 'date'],
                'parent_expense_id' => ['nullable', 'exists:expenses,id'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            if ((float) $expense->funded_amount > (float) $expense->amount) {
                throw new DomainException('El monto pagado desde caja no puede exceder el monto del gasto.');
            }

            if ($expense->status === ExpenseStatus::Paid && (float) $expense->funded_amount < (float) $expense->amount) {
                throw new DomainException('Un gasto pagado debe tener el monto total cubierto desde caja.');
            }

            if ($expense->status !== ExpenseStatus::Paid && $expense->paid_at !== null) {
                throw new DomainException('Solo un gasto pagado puede tener fecha de pago.');
            }

            if (! $expense->exists) {
                return;
            }

            /** @var self $original */
            $original = self::query()->findOrFail($expense->id);

            if ($original->historical_distribution_blocked && $expense->isDirty('historical_distribution_blocked')) {
                throw new DomainException('La exclusión histórica solo se modifica mediante una operación histórica autorizada.');
            }
            if ($original->historical_distribution_blocked && $original->status === ExpenseStatus::Registered
                && array_diff(array_keys($expense->getDirty()), ['updated_at']) !== []) {
                throw new DomainException('El gasto histórico registrado requiere una operación histórica autorizada para modificarse.');
            }

            if ($original->status === ExpenseStatus::Registered) {
                return;
            }

            if ($original->status === ExpenseStatus::Distributed) {
                $dirty = array_keys($expense->getDirty());
                $allowedForFunding = ['funded_amount', 'status', 'paid_at', 'updated_at'];
                $blockedAttributes = array_diff($dirty, $allowedForFunding);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se puede editar un gasto distribuido fuera de campos de pago.');
                }

                if ($expense->isDirty('status') && ! in_array($expense->status, [ExpenseStatus::Distributed, ExpenseStatus::Paid, ExpenseStatus::Cancelled], true)) {
                    throw new DomainException('Un gasto distribuido solo puede pasar a pagado o cancelado.');
                }

                return;
            }

            if ($original->status === ExpenseStatus::Paid) {
                $dirty = array_keys($expense->getDirty());
                $allowedForFunding = ['funded_amount', 'status', 'paid_at', 'updated_at'];
                $blockedAttributes = array_diff($dirty, $allowedForFunding);

                if ($blockedAttributes !== []) {
                    throw new DomainException('No se puede editar un gasto pagado fuera de campos de pago.');
                }

                if ($expense->isDirty('status') && ! in_array($expense->status, [ExpenseStatus::Distributed, ExpenseStatus::Paid, ExpenseStatus::Cancelled], true)) {
                    throw new DomainException('Un gasto pagado solo puede pasar a distribuido o cancelado.');
                }

                return;
            }

            if ($original->status === ExpenseStatus::Cancelled && $expense->status === ExpenseStatus::Registered) {
                throw new DomainException('Un gasto cancelado no puede volver a registrado.');
            }

            $dirty = array_keys($expense->getDirty());
            $blockedAttributes = array_diff($dirty, ['updated_at']);

            if ($blockedAttributes !== []) {
                throw new DomainException('No se puede editar un gasto pagado o cancelado.');
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PartnerCharge::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_expense_id');
    }

    public function fundingCollections(): HasMany
    {
        return $this->hasMany(ExpenseFundingPayment::class);
    }

    #[Scope]
    protected function registered(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Registered->value);
    }

    #[Scope]
    protected function distributed(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Distributed->value);
    }

    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Paid->value);
    }

    #[Scope]
    protected function cancelled(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Cancelled->value);
    }

    #[Scope]
    protected function byCategory(Builder $query, int $categoryId): void
    {
        $query->where('expense_category_id', $categoryId);
    }
}
