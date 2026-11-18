<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareContribution extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'quantity' => 'decimal:4', 'reference_date' => 'date', 'context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('Los aportes se corrigen mediante movimientos, no se sobrescriben.');
        });
        static::deleting(function (): void {
            throw new DomainException('El historial de aportes no puede eliminarse.');
        });
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(ShareHolding::class, 'share_holding_id');
    }
}
