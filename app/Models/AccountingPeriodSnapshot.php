<?php

namespace App\Models;

use Database\Factories\AccountingPeriodSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriodSnapshot extends Model
{
    /** @use HasFactory<AccountingPeriodSnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'accounting_period_id',
        'total_charges',
        'total_collections',
        'total_allocations',
        'pending_balance',
        'credit_balance',
        'overdue_1_30',
        'overdue_31_60',
        'overdue_61_90',
        'overdue_90_plus',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_charges' => 'decimal:2',
            'total_collections' => 'decimal:2',
            'total_allocations' => 'decimal:2',
            'pending_balance' => 'decimal:2',
            'credit_balance' => 'decimal:2',
            'overdue_1_30' => 'decimal:2',
            'overdue_31_60' => 'decimal:2',
            'overdue_61_90' => 'decimal:2',
            'overdue_90_plus' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }
}
