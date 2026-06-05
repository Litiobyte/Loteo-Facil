<?php

namespace App\Models;

use Database\Factories\LoteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Lote extends Model
{
    /** @use HasFactory<LoteFactory> */
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'codigo',
        'estado',
        'hectareas',
        'metros_cuadrados',
        'etapa_id',
        'valor_lote',
        'notas',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $lote): void {
            if ($lote->metros_cuadrados !== null) {
                $lote->hectareas = $lote->metros_cuadrados / 10000;
            }
        });
    }

    protected function hectareas(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->metros_cuadrados / 10000,
        );
    }

    public function propietarios(): BelongsToMany
    {
        return $this->belongsToMany(Propietario::class, 'lote_propietario')
            ->withPivot(['assigned_at', 'unassigned_at', 'status'])
            ->withTimestamps();
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }
}
