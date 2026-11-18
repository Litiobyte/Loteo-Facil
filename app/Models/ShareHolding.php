<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShareHolding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'square_meters_per_share' => 'decimal:4', 'acquired_at' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $holding): void {
            if (BigDecimal::of($holding->quantity)->isNegative() || ! in_array($holding->status, ['active', 'inactive'], true)) {
                throw new DomainException('Participación inválida.');
            }
            if ($holding->exists && $holding->isDirty(['propietario_id', 'square_meters_per_share', 'acquired_at'])) {
                throw new DomainException('La titularidad y equivalencia originales no se editan; utilice una transferencia.');
            }
        });
        static::deleting(function (): void {
            throw new DomainException('Las participaciones se conservan para auditoría.');
        });
    }

    public function equivalentSquareMeters(): string
    {
        return (string) BigDecimal::of($this->quantity)->multipliedBy($this->square_meters_per_share);
    }

    /** Muestra sin decimales innecesarios: 4.0000 → 4, 0.5000 → 0.5. */
    public static function displayQuantity(?string $quantity): string
    {
        return $quantity === null ? '' : (string) BigDecimal::of($quantity)->stripTrailingZeros();
    }

    public function propietario(): BelongsTo
    {
        return $this->belongsTo(Propietario::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ShareMovement::class);
    }
}
