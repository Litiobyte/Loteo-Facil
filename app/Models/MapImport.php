<?php

namespace App\Models;

use Database\Factories\MapImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MapImport extends Model
{
    /** @use HasFactory<MapImportFactory> */
    use HasFactory;

    protected $fillable = [
        'etapa_id',
        'filename',
        'original_path',
        'status',
        'summary',
        'uploaded_by',
    ];

    protected $casts = [
        'summary' => 'array',
    ];

    public function geometries(): HasMany
    {
        return $this->hasMany(MapGeometry::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }
}
