<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_expense_category_with_valid_data(): void
    {
        $category = ExpenseCategory::query()->create([
            'name' => 'Contador',
            'description' => 'Honorarios contables del proyecto.',
            'is_active' => true,
        ]);

        $this->assertModelExists($category);
        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'Contador',
            'is_active' => 1,
        ]);
    }

    public function test_name_is_required_when_creating_expense_category(): void
    {
        $this->expectException(ValidationException::class);

        ExpenseCategory::query()->create([
            'name' => '',
            'description' => 'Sin nombre válido.',
        ]);
    }

    public function test_name_must_be_unique_for_expense_category(): void
    {
        ExpenseCategory::factory()->create([
            'name' => 'Legal',
        ]);

        $this->expectException(ValidationException::class);

        ExpenseCategory::factory()->create([
            'name' => 'Legal',
        ]);
    }

    public function test_description_cannot_exceed_500_characters(): void
    {
        $this->expectException(ValidationException::class);

        ExpenseCategory::factory()->create([
            'description' => str_repeat('a', 501),
        ]);
    }

    public function test_can_update_expense_category(): void
    {
        $category = ExpenseCategory::factory()->create([
            'name' => 'Trámites',
        ]);

        $category->update([
            'description' => 'Gestiones actualizadas.',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'description' => 'Gestiones actualizadas.',
            'is_active' => 0,
        ]);
    }

    public function test_can_delete_expense_category_without_expenses(): void
    {
        $category = ExpenseCategory::factory()->create();

        $category->delete();

        $this->assertModelMissing($category);
    }
}
