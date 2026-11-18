<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class ShareMovement extends Model
{
    protected $guarded = ['id'];

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
