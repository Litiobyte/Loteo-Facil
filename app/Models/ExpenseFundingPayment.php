<?php

namespace App\Models;

use App\Domain\Accounting\Services\MonthlyClosingService;
use Database\Factories\ExpenseFundingPaymentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExpenseFundingPayment extends Model
{
    /** @use HasFactory<ExpenseFundingPaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'expense_id',
        'amount',
        'payment_date',
        'notes',
        'is_void',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_void' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'is_void' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $validator = Validator::make($payment->attributesToArray(), [
                'expense_id' => ['required', 'exists:expenses,id'],
                'amount' => ['required', 'numeric', 'gt:0'],
                'payment_date' => ['required', 'date', 'before_or_equal:today'],
                'notes' => ['nullable', 'string'],
                'is_void' => ['required', 'boolean'],
                'voided_at' => ['nullable', 'date'],
                'voided_by' => ['nullable', 'exists:users,id'],
                'void_reason' => ['nullable', 'string'],
                'created_by' => ['nullable', 'exists:users,id'],
                'updated_by' => ['nullable', 'exists:users,id'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            if (app(MonthlyClosingService::class)->isDateInClosedPeriod($payment->payment_date)) {
                throw new DomainException('No se pueden registrar ni editar egresos en un período contable cerrado.');
            }
        });
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
