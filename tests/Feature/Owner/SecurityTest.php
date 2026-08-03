<?php

namespace Tests\Feature\Owner;

use App\Filament\Owner\Pages\EstadoDeCuenta;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Models\Collection;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('propietario', 'web');
    }

    public function test_propietario_a_cannot_see_propietario_b_charges(): void
    {
        [$userA, $ownerA] = $this->createOwner();
        [, $ownerB] = $this->createOwner();

        $chargeA = PartnerCharge::factory()->create(['propietario_id' => $ownerA->id]);
        $chargeB = PartnerCharge::factory()->create(['propietario_id' => $ownerB->id]);

        $this->actingAs($userA);

        $ids = MisCobrosResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($chargeA->id, $ids);
        $this->assertNotContains($chargeB->id, $ids);
    }

    public function test_propietario_a_cannot_see_propietario_b_collections(): void
    {
        [$userA, $ownerA] = $this->createOwner();
        [, $ownerB] = $this->createOwner();

        $paymentA = Collection::factory()->create(['propietario_id' => $ownerA->id]);
        $paymentB = Collection::factory()->create(['propietario_id' => $ownerB->id]);

        $this->actingAs($userA);

        $ids = MyCollectionsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($paymentA->id, $ids);
        $this->assertNotContains($paymentB->id, $ids);
    }

    public function test_propietario_a_cannot_see_propietario_b_statement(): void
    {
        [$userA, $ownerA] = $this->createOwner();
        [, $ownerB] = $this->createOwner();

        PartnerCharge::factory()->create(['propietario_id' => $ownerA->id, 'amount' => 100000, 'remaining_amount' => 100000]);
        PartnerCharge::factory()->create(['propietario_id' => $ownerB->id, 'amount' => 250000, 'remaining_amount' => 250000]);

        $this->actingAs($userA);

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertNotNull($data['statement']);
        $this->assertCount(1, $data['statement']['charges']);
        $this->assertSame($ownerA->id, $data['statement']['owner']['id']);
    }

    public function test_user_without_propietario_sees_empty_or_error(): void
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        $this->actingAs($user);

        $chargesCount = MisCobrosResource::getEloquentQuery()->count();
        $collectionsCount = MyCollectionsResource::getEloquentQuery()->count();

        $page = new EstadoDeCuenta;
        $data = $this->invokeProtectedMethod($page, 'getViewData');

        $this->assertSame(0, $chargesCount);
        $this->assertSame(0, $collectionsCount);
        $this->assertNull($data['statement']);
    }

    public function test_scopes_are_applied_correctly(): void
    {
        [$userA, $ownerA] = $this->createOwner();
        [, $ownerB] = $this->createOwner();

        PartnerCharge::factory()->count(2)->create(['propietario_id' => $ownerA->id]);
        PartnerCharge::factory()->count(3)->create(['propietario_id' => $ownerB->id]);
        Collection::factory()->count(1)->create(['propietario_id' => $ownerA->id]);
        Collection::factory()->count(4)->create(['propietario_id' => $ownerB->id]);

        $this->actingAs($userA);

        $this->assertSame(3, MisCobrosResource::getEloquentQuery()->count());
        $this->assertSame(1, MyCollectionsResource::getEloquentQuery()->count());
    }

    /**
     * @return array{0: User, 1: Propietario}
     */
    private function createOwner(): array
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $owner = Propietario::factory()->create(['user_id' => $user->id]);

        return [$user, $owner];
    }

    private function invokeProtectedMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
