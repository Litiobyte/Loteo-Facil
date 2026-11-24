<?php

namespace App\Models;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Support\ChileanRut;
use Brick\Math\BigDecimal;
use Database\Factories\PropietarioFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Campos que el propietario debe completar en "Mi perfil" del portal
     * (debe coincidir con ManageUsers::profileFields()).
     *
     * @var list<string>
     */
    public const PROFILE_FIELDS = [
        'nombre',
        'apellido',
        'rut',
        'profesion',
        'nacionalidad',
        'email',
        'telefono',
        'direccion',
        'region_id',
        'comuna_id',
    ];

    public function isProfileComplete(): bool
    {
        return collect(static::PROFILE_FIELDS)->every(fn (string $field): bool => filled($this->{$field}));
    }

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

    public function secondaryUsers(): HasMany
    {
        return $this->hasMany(User::class, 'secondary_propietario_id');
    }

    public function lotes(): BelongsToMany
    {
        return $this->belongsToMany(Lote::class, 'lote_propietario')
            ->withPivot(['assigned_at', 'unassigned_at', 'status'])
            ->withTimestamps();
    }

    public function shareHoldings(): HasMany
    {
        return $this->hasMany(ShareHolding::class);
    }

    public function shareContributions(): HasMany
    {
        return $this->hasMany(ShareContribution::class);
    }

    /** @return array{quantity: string, square_meters: string} */
    public function shareTotals(): array
    {
        $quantity = BigDecimal::zero();
        $area = BigDecimal::zero();
        $holdings = $this->relationLoaded('shareHoldings') ? $this->shareHoldings : $this->shareHoldings()->get();
        foreach ($holdings->where('status', 'active') as $holding) {
            $quantity = $quantity->plus($holding->quantity);
            $area = $area->plus($holding->equivalentSquareMeters());
        }

        return ['quantity' => (string) $quantity, 'square_meters' => (string) $area];
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

    public function charges(): HasMany
    {
        return $this->hasMany(PartnerCharge::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function chargesPending(): HasMany
    {
        return $this->charges()->where('status', ChargeStatus::Pending->value);
    }

    public function totalPendingAmount(): float
    {
        return (float) $this->chargesPending()->sum('remaining_amount');
    }
}
