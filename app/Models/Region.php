<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use HasFactory;

    protected $table = 'regiones';

    protected $fillable = [
        'nombre',
    ];

    public function comunas(): HasMany
    {
        return $this->hasMany(Comuna::class);
    }

    public function propietarios(): HasMany
    {
        return $this->hasMany(Propietario::class);
    }
}
