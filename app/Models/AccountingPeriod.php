<?php

namespace App\Models;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use Database\Factories\AccountingPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountingPeriod extends Model
{
    /** @use HasFactory<AccountingPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'year',
        'month',
        'period_start',
        'period_end',
        'status',
        'close_folio',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => AccountingPeriodStatus::Open->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => AccountingPeriodStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(AccountingPeriodSnapshot::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
