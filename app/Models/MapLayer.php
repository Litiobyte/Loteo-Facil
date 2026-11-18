<?php

namespace App\Models;

use Database\Factories\MapLayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MapLayer extends Model
{
    /** @use HasFactory<MapLayerFactory> */
    use HasFactory;

    protected $fillable = [
        'etapa_id',
        'slug',
        'name',
        'description',
        'color',
        'opacity',
        'sort_order',
        'is_visible',
        'is_public',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_public' => 'boolean',
    ];

    public function geometries(): HasMany
    {
        return $this->hasMany(MapGeometry::class);
    }
}
