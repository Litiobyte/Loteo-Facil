<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Admin\Resources\SupplierResource;
use App\Filament\Admin\Resources\SupplierResource\Pages\ManageSuppliers;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_supplier_relationship_and_delete_restriction(): void
    {
        $supplier = Supplier::factory()->create();
        $expense = Expense::factory()->create(['supplier_id' => $supplier->id]);
        $withoutSupplier = Expense::factory()->create();
        $this->assertTrue($expense->supplier->is($supplier));
        $this->assertNull($withoutSupplier->supplier);
        $this->assertTrue($supplier->expenses->contains($expense));
        $this->assertFalse(SupplierResource::canDelete($supplier));

        $this->expectException(QueryException::class);
        $supplier->delete();
    }

    public function test_duplicate_name_is_rejected(): void
    {
        Supplier::factory()->create(['name' => 'Proveedor único']);
        $this->expectException(ValidationException::class);
        Supplier::factory()->create(['name' => 'Proveedor único']);
    }

    public function test_invalid_supplier_reference_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        Expense::factory()->create(['supplier_id' => 999999]);
    }

    public function test_admin_can_create_supplier_in_catalog(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ManageSuppliers::class)
            ->callAction('create', [
                'name' => 'Proveedor de prueba',
                'rut' => '12.345.678-5',
                'service' => 'Mantención',
                'phone' => '+56912345678',
                'email' => 'proveedor@example.com',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Proveedor de prueba',
            'rut' => '12345678-5',
            'service' => 'Mantención',
            'phone' => '+56912345678',
            'email' => 'proveedor@example.com',
        ]);
    }

    public function test_supplier_form_requires_contact_fields_and_validates_rut_and_email(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ManageSuppliers::class)
            ->callAction('create', ['name' => 'Sin datos'])
            ->assertHasActionErrors(['rut', 'service', 'phone', 'email']);

        Livewire::test(ManageSuppliers::class)
            ->callAction('create', [
                'name' => 'Datos inválidos',
                'rut' => '12.345.678-9',
                'service' => 'Mantención',
                'phone' => '+56912345678',
                'email' => 'correo-inválido',
            ])
            ->assertHasActionErrors(['rut', 'email']);

        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_admin_can_reuse_supplier_on_expense_and_recurring_series(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $supplier = Supplier::factory()->create();
        $category = ExpenseCategory::factory()->create();

        foreach ([false, true] as $recurring) {
            Livewire::test(CreateExpense::class)->fillForm([
                'title' => $recurring ? 'Serie proveedor' : 'Gasto proveedor',
                'expense_category_id' => $category->id,
                'supplier_id' => $supplier->id,
                'amount' => 1000,
                'expense_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                'distribution_type' => 'equal_by_partner',
                'is_recurring' => $recurring,
                'recurrence_end_date' => $recurring ? now()->startOfMonth()->toDateString() : null,
            ])->call('create')->assertHasNoFormErrors();
        }

        $head = Expense::query()->where('title', 'Serie proveedor')->whereNull('parent_expense_id')->firstOrFail();
        $this->assertSame($supplier->id, $head->supplier_id);
        $this->assertSame(2, $head->children()->where('supplier_id', $supplier->id)->count());
        $expense = Expense::query()->where('title', 'Gasto proveedor')->firstOrFail();
        $this->assertSame($supplier->id, $expense->supplier_id);
        $copy = ExpenseResource::duplicateExpense($expense, ['amount' => 1000, 'expense_date' => now()->toDateString()]);
        $this->assertSame($supplier->id, $copy->supplier_id);
        $this->assertSame(1, Supplier::count());
    }

    public function test_supplier_resource_is_limited_to_administrators(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $owner = User::factory()->create();
        $owner->assignRole('propietario');
        $this->actingAs($admin);
        $this->assertTrue(SupplierResource::canAccess());
        $this->actingAs($owner);
        $this->assertFalse(SupplierResource::canAccess());
    }
}
