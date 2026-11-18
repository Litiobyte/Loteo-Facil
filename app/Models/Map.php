<?php

namespace App\Models;

use Database\Factories\MapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Map extends Model
{
    /** @use HasFactory<MapFactory> */
    use HasFactory;

    protected $fillable = [
        'etapa_id',
        'published_import_id',
    ];

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function publishedImport(): BelongsTo
    {
        return $this->belongsTo(MapImport::class, 'published_import_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(MapImport::class, 'etapa_id', 'etapa_id');
    }

    public function layers(): HasMany
    {
        return $this->hasMany(MapLayer::class, 'etapa_id', 'etapa_id');
    }
}
