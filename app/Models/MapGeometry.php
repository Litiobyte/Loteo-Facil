<?php

namespace App\Models;

use App\Domain\Maps\Enums\AssociationMatch;
use Database\Factories\MapGeometryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapGeometry extends Model
{
    /** @use HasFactory<MapGeometryFactory> */
    use HasFactory;

    protected $fillable = [
        'map_import_id',
        'map_layer_id',
        'lote_id',
        'geometry',
        'properties',
        'normalized_identifier',
        'association_status',
        'association_method',
        'is_confirmed',
        'match_confidence',
        'area_m2',
    ];

    protected $casts = [
        'geometry' => 'array',
        'properties' => 'array',
        'is_confirmed' => 'boolean',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(MapImport::class, 'map_import_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(MapLayer::class, 'map_layer_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function match(): AssociationMatch
    {
        return AssociationMatch::from($this->association_status);
    }
}
