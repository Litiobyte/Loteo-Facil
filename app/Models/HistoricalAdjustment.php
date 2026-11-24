<?php

namespace App\Models;

use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => HistoricalAdjustmentCategory::class,
            'amount' => 'decimal:2',
            'effective_date' => 'date',
            'registered_at' => 'datetime',
            'from_import' => 'boolean',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HistoricalAdjustmentAllocation::class);
    }
}
