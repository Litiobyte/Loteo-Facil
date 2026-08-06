<?php

namespace App\Models;

use App\Support\ChileanRut;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $name
 * @property string $rut
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'rut',
        'phone',
        'email',
        'address',
        'notes',
    ];

    protected function rut(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => ChileanRut::normalize($value),
        );
    }

    protected static function booted(): void
    {
        static::saving(function (self $supplier): void {
            $validator = Validator::make($supplier->attributesToArray(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('suppliers', 'name')->ignore($supplier->id),
                ],
                'rut' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('suppliers', 'rut')->ignore($supplier->id),
                ],
                'phone' => ['nullable', 'string', 'max:50'],
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('suppliers', 'email')->ignore($supplier->id),
                ],
                'address' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        });
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
