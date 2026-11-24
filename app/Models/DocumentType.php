<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $guarded = ['id', 'community_id'];

    protected $attributes = ['community_id' => 1];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $type): void {
            if ($type->documents()->withTrashed()->exists()) {
                throw new DomainException('No se puede eliminar un tipo con documentos asociados.');
            }
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(DocumentTypeModule::class);
    }
}
