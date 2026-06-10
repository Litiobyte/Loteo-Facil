<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
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
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $validator = Validator::make($category->attributesToArray(), [
                'name' => [
                    'required',
                    'string',
                    'max:100',
                    Rule::unique('expense_categories', 'name')->ignore($category->id),
                ],
                'description' => ['nullable', 'string', 'max:500'],
                'is_active' => ['required', 'boolean'],
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
