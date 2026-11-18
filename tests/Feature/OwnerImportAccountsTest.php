<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\AdministrativeImportService;
use App\Domain\Imports\Services\OwnerImportAccountService;
use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerImportAccountsTest extends TestCase
{
    use RefreshDatabase;

    private AdministrativeImportService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['super_admin', 'admin', 'propietario'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        CommunitySetting::current(); // asegura fila id 1
        $this->service = app(AdministrativeImportService::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_row_without_user_id_creates_account_owner_role_and_initial_password(): void
    {
        $import = $this->service->start($this->csv([['12.345.678-5', 'Ana', 'Pérez', 'ana@example.cl', '']]), 'owners', $this->admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3], 'skip', $this->admin);
        $this->assertSame(1, $preview->created_count);

        $this->service->execute($preview, $this->admin, true);

        $owner = Propietario::query()->where('rut', '12345678-5')->firstOrFail();
        $account = User::query()->where('email', 'ana@example.cl')->firstOrFail();
        $this->assertSame($account->id, $owner->user_id);
        $this->assertTrue($account->hasRole('propietario'));
        $this->assertTrue($account->must_change_password);
        $this->assertTrue(app('hash')->check('12345678-5perez', $account->password));
    }

    public function test_row_with_eligible_user_id_does_not_create_account(): void
    {
        $account = User::factory()->create();
        $account->assignRole('propietario');
        $import = $this->service->start($this->csv([['12.345.678-5', 'Ana', 'Pérez', 'ana@example.cl', $account->id]]), 'owners', $this->admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $this->admin);
        $this->service->execute($preview, $this->admin, true);

        $this->assertSame(2, User::query()->count());
        $this->assertTrue($account->refresh()->propietario()->exists());
        $this->assertFalse($account->must_change_password);
    }

    #[DataProvider('missingEmailRows')]
    public function test_missing_email_is_generated_from_rut_and_assigned_to_account_and_owner(string $rut, bool $mapEmail, string $expectedEmail): void
    {
        $import = $this->service->start($this->csv([[$rut, 'Ana', 'Pérez', '   ', '']]), 'owners', $this->admin);
        $mapping = ['rut' => 0, 'nombre' => 1, 'apellido' => 2];
        if ($mapEmail) {
            $mapping['email'] = 3;
        }
        $preview = $this->service->preview($import, $mapping, 'skip', $this->admin);

        $this->assertSame(0, $preview->error_count);
        $this->assertSame(1, $preview->created_count);
        $this->assertSame($expectedEmail, $preview->rows()->firstOrFail()->data['email']);

        $executed = $this->service->execute($preview, $this->admin, true);

        $this->assertSame('completed', $executed->status);
        $account = User::query()->where('email', $expectedEmail)->firstOrFail();
        $this->assertDatabaseHas('propietarios', ['user_id' => $account->id, 'email' => $expectedEmail]);
        $this->assertTrue($account->hasRole('propietario'));
        $this->assertTrue($account->must_change_password);
    }

    public static function missingEmailRows(): array
    {
        return [
            'blank email' => ['12.345.678-5', true, '123456785@loteofacil.cl'],
            'unmapped email' => ['123456785', false, '123456785@loteofacil.cl'],
            'letter check digit' => ['6-k', true, '6k@loteofacil.cl'],
        ];
    }

    public function test_generated_email_already_used_by_an_account_is_rejected(): void
    {
        User::factory()->create(['email' => '123456785@loteofacil.cl']);
        $import = $this->service->start($this->csv([['12.345.678-5', 'Ana', 'Pérez', '', '']]), 'owners', $this->admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3], 'skip', $this->admin);

        $this->assertSame(1, $preview->error_count);
        $this->assertStringContainsString('El correo ya tiene una cuenta', implode(' ', $preview->rows()->firstOrFail()->errors));
        $this->service->execute($preview, $this->admin, true);

        $this->assertSame(2, User::query()->count());
        $this->assertDatabaseMissing('propietarios', ['rut' => '12345678-5']);
    }

    public function test_missing_email_does_not_allow_invalid_rut(): void
    {
        $import = $this->service->start($this->csv([['12.345.678-0', 'Ana', 'Pérez', '', '']]), 'owners', $this->admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3], 'skip', $this->admin);

        $this->assertSame(1, $preview->error_count);
        $this->service->execute($preview, $this->admin, true);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Propietario::query()->count());
    }

    public function test_occupied_email_or_ineligible_user_id_is_rejected_without_changes(): void
    {
        $linked = User::factory()->create();
        $linked->assignRole('propietario');
        Propietario::factory()->create(['user_id' => $linked->id, 'rut' => '98765432-1']);

        $import = $this->service->start($this->csv([
            ['12.345.678-5', 'Ana', 'Pérez', $linked->email, ''],
            ['11.222.333-4', 'Beto', 'Rojas', '', (string) $linked->id],
            ['11.333.444-5', 'Cara', 'Soto', 'ok@example.cl', (string) $linked->id],
        ]), 'owners', $this->admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $this->admin);
        $this->assertSame(3, $preview->error_count);
        $this->service->execute($preview, $this->admin, true);

        $this->assertSame(2, User::query()->count());
        $this->assertDatabaseMissing('propietarios', ['rut' => '12345678-5']);
    }

    public function test_initial_password_normalizes_accents_spaces_and_composed_rut(): void
    {
        $service = app(OwnerImportAccountService::class);
        $this->assertSame('12345678-5perezgomez', $service->initialPassword('12.345.678-5', 'Pérez Gómez'));
        $this->assertSame('9nandu', $service->initialPassword('9', 'ÑáNdú'));
    }

    private function csv(array $rows): UploadedFile
    {
        $content = "RUT,Nombre,Apellido,Email,UserID\n".implode("\n", array_map(fn (array $row): string => implode(',', array_map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"', $row)), $rows));
        Storage::disk('local')->put('tmp/owners.csv', $content);

        return new UploadedFile(Storage::disk('local')->path('tmp/owners.csv'), 'owners.csv', 'text/csv', null, true);
    }
}
