<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerChargeConsolidationLink extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function consolidatedCharge(): BelongsTo
    {
        return $this->belongsTo(PartnerCharge::class, 'consolidated_charge_id');
    }
}
