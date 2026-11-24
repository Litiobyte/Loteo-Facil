<?php

namespace App\Models\Concerns;

use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasDocuments
{
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable', 'documentables')
            ->withPivot('created_at');
    }
}
