<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'data' => 'array', 'overrides' => 'array', 'changes' => 'array', 'errors' => 'array'];
    }
}
