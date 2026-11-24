<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HistoricalExternalIdentity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'community_scope',
        'source',
        'movement_type',
        'external_id',
        'identifiable_type',
        'identifiable_id',
    ];

    public function identifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
