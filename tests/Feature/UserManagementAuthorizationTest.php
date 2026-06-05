<?php

namespace Tests\Feature;

use App\Domain\Users\Services\UserRoleAssignmentService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_admin_can_only_assign_propietario_role(): void
    {
        $service = app(UserRoleAssignmentService::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(['propietario'], $service->allowedRolesFor($admin));
    }

    public function test_admin_cannot_assign_super_admin_role(): void
    {
        $this->expectException(AuthorizationException::class);

        $service = app(UserRoleAssignmentService::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $target = User::factory()->create();

        $service->assignRole($admin, $target, 'super_admin');
    }

    public function test_admin_policy_allows_manage_only_propietario_users(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $propietario = User::factory()->create();
        $propietario->assignRole('propietario');

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));

        $this->assertTrue(Gate::forUser($admin)->allows('update', $propietario));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $propietario));

        $this->assertFalse(Gate::forUser($admin)->allows('update', $otherAdmin));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $otherAdmin));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $superAdmin));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $superAdmin));
    }

    public function test_propietario_policy_can_view_self_but_cannot_create_or_manage_users(): void
    {
        $propietario = User::factory()->create();
        $propietario->assignRole('propietario');

        $otherPropietario = User::factory()->create();
        $otherPropietario->assignRole('propietario');

        $this->assertTrue(Gate::forUser($propietario)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($propietario)->allows('view', $propietario));
        $this->assertFalse(Gate::forUser($propietario)->allows('view', $otherPropietario));
        $this->assertFalse(Gate::forUser($propietario)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($propietario)->allows('update', $propietario));
        $this->assertFalse(Gate::forUser($propietario)->allows('delete', $propietario));
    }
}
