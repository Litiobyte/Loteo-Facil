<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataImport extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'uploaded',
        'duplicate_mode' => 'skip',
        'total' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'skipped_count' => 0,
        'error_count' => 0,
    ];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'executed_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function communitySetting(): BelongsTo
    {
        return $this->belongsTo(CommunitySetting::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }
}
