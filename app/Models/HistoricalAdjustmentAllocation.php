<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalAdjustmentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_date' => 'date'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(HistoricalAdjustment::class, 'historical_adjustment_id');
    }
}
