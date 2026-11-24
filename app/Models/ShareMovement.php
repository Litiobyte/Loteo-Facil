<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareMovement extends Model
{
    protected $guarded = ['id'];

    public function holding(): BelongsTo
    {
        return $this->belongsTo(ShareHolding::class, 'share_holding_id');
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'quantity_before' => 'decimal:4', 'quantity_after' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('El historial de acciones es inmutable.');
        });
        static::deleting(function (): void {
            throw new DomainException('El historial de acciones es inmutable.');
        });
    }
}
