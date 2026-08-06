<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Suppliers\SupplierResource;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\ValidChileanRut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_resource_is_restricted_to_admin_and_super_admin_roles(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $owner->assignRole('propietario');

        $this->actingAs($superAdmin);
        $this->assertTrue(SupplierResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(SupplierResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(SupplierResource::canAccess());
    }

    public function test_admin_can_view_suppliers_index_page(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        $this->get('/admin/suppliers')->assertOk();
    }

    public function test_can_create_and_edit_supplier(): void
    {
        $supplier = Supplier::factory()->create([
            'name' => 'Constructora Andes',
        ]);

        $this->assertModelExists($supplier);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Constructora Andes',
        ]);

        $supplier->update([
            'name' => 'Constructora Andes Spa',
            'phone' => '+56 2 2345 6789',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Constructora Andes Spa',
            'phone' => '+56 2 2345 6789',
        ]);
    }

    public function test_invalid_rut_is_rejected_by_rule(): void
    {
        $failures = [];

        (new ValidChileanRut)->validate('rut', '12345678-9', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertNotEmpty($failures);
    }

    public function test_rut_is_required_when_creating_supplier(): void
    {
        $this->expectException(ValidationException::class);

        Supplier::factory()->create(['rut' => null]);
    }

    public function test_rut_is_normalized_when_creating_supplier(): void
    {
        $supplier = Supplier::factory()->create([
            'rut' => '11.111.111-1',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'rut' => '11111111-1',
        ]);
    }

    public function test_rut_must_be_unique(): void
    {
        Supplier::factory()->create(['rut' => '11111111-1']);

        $this->expectException(ValidationException::class);

        Supplier::factory()->create(['rut' => '11111111-1']);
    }

    public function test_email_must_be_unique(): void
    {
        Supplier::factory()->create(['email' => 'proveedor@example.cl']);

        $this->expectException(ValidationException::class);

        Supplier::factory()->create(['email' => 'proveedor@example.cl']);
    }

    public function test_can_delete_supplier_without_expenses(): void
    {
        $supplier = Supplier::factory()->create();

        $supplier->delete();

        $this->assertModelMissing($supplier);
    }

    public function test_delete_is_blocked_when_supplier_has_associated_expenses(): void
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $supplierWithExpense = Supplier::factory()->create();
        Expense::factory()->create(['supplier_id' => $supplierWithExpense->id]);

        $emptySupplier = Supplier::factory()->create();

        $this->actingAs($admin);

        $this->assertFalse(SupplierResource::canDelete($supplierWithExpense));
        $this->assertTrue(SupplierResource::canDelete($emptySupplier));
    }
}
