<?php

namespace App\Models;

use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = ['name', 'rut', 'service', 'phone', 'email'];

    protected function rut(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => ChileanRut::normalize($value));
    }

    protected static function booted(): void
    {
        static::saving(function (self $supplier): void {
            Validator::make($supplier->attributesToArray(), [
                'name' => ['required', 'string', 'max:100', Rule::unique('suppliers', 'name')->ignore($supplier->id)],
                'rut' => ['nullable', 'string', 'max:12', new ValidChileanRut],
                'service' => ['nullable', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'string', 'email', 'max:255'],
            ])->validate();
        });
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
