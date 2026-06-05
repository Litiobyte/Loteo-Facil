<?php

namespace App\Models;

use App\Support\ChileanRut;
use Database\Factories\PropietarioFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Propietario extends Model
{
    /** @use HasFactory<PropietarioFactory> */
    use HasFactory;

    protected $table = 'propietarios';

    protected $fillable = [
        'user_id',
        'nombre',
        'apellido',
        'rut',
        'telefono',
        'direccion',
        'comuna_id',
        'region_id',
        'nacionalidad',
        'profesion',
        'estado_civil',
        'email',
    ];

    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->nombre} {$this->apellido}"),
        );
    }

    protected function rut(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => ChileanRut::normalize($value),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lotes(): BelongsToMany
    {
        return $this->belongsToMany(Lote::class, 'lote_propietario')
            ->withPivot(['assigned_at', 'unassigned_at', 'status'])
            ->withTimestamps();
    }

    public function lotesActivos(): BelongsToMany
    {
        return $this->lotes()->wherePivot('status', 'active');
    }

    public function hectareasTotalesActivas(): float
    {
        return (float) ($this->lotesActivos()->sum('lotes.metros_cuadrados') / 10000);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function comuna(): BelongsTo
    {
        return $this->belongsTo(Comuna::class);
    }
}
