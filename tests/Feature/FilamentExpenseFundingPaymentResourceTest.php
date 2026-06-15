<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ExpenseFundingPayments\ExpenseFundingPaymentResource;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentExpenseFundingPaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_funding_payment_resource_access_is_restricted_to_admin_and_super_admin_roles(): void
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
        $this->assertTrue(ExpenseFundingPaymentResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(ExpenseFundingPaymentResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(ExpenseFundingPaymentResource::canAccess());
    }

    public function test_admin_panel_registers_expense_funding_payment_resource(): void
    {
        $resources = app(PanelRegistry::class)->get('admin')->getResources();

        $this->assertContains(ExpenseFundingPaymentResource::class, $resources);
    }
}
