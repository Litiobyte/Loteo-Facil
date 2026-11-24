<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalReconciliation extends Model
{
    public const KindPaymentRecognition = 'payment_recognition';

    public const KindDifferenceClassification = 'difference_classification';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'expected_debt' => 'decimal:2',
            'calculated_debt' => 'decimal:2',
            'difference' => 'decimal:2',
            'final_calculated_debt' => 'decimal:2',
            'summary' => 'array',
        ];
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
