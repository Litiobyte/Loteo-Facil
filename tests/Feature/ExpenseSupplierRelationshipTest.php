<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseSupplierRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_belongs_to_supplier(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Ferretería El Constructor']);
        $expense = Expense::factory()->create(['supplier_id' => $supplier->id]);

        $this->assertSame($supplier->id, $expense->supplier_id);
        $this->assertTrue($expense->supplier->is($supplier));
        $this->assertTrue($supplier->expenses->contains($expense));
    }

    public function test_expense_can_exist_without_supplier(): void
    {
        $expense = Expense::factory()->create(['supplier_id' => null]);

        $this->assertNull($expense->supplier_id);
        $this->assertNull($expense->supplier);
    }

    public function test_supplier_can_have_multiple_expenses(): void
    {
        $supplier = Supplier::factory()->create();
        Expense::factory()->count(2)->create(['supplier_id' => $supplier->id]);

        $this->assertSame(2, $supplier->expenses()->count());
        $this->assertSame(2, Expense::query()->where('supplier_id', $supplier->id)->count());
    }

    public function test_supplier_id_must_reference_existing_supplier(): void
    {
        $this->expectException(ValidationException::class);

        Expense::factory()->create(['supplier_id' => 999999]);
    }

    public function test_duplicate_expense_copies_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->registered()->create([
            'supplier_id' => $supplier->id,
        ]);

        $duplicate = ExpenseResource::duplicateExpense($expense, [
            'expense_date' => now()->toDateString(),
            'amount' => (float) $expense->amount,
        ]);

        $this->assertSame($supplier->id, $duplicate->supplier_id);
    }
}
