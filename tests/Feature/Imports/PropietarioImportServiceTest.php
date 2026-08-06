<?php

namespace Tests\Feature\Imports;

use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\PropietarioImportService;
use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PropietarioImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function file(array $rows): ParsedFile
    {
        $data = [];

        foreach ($rows as $rowNumber => $dataRow) {
            $data[] = ['row_number' => $rowNumber, 'data' => $dataRow];
        }

        return new ParsedFile(PropietarioImportService::HEADERS, $data);
    }

    private function createRegion(): array
    {
        $region = Region::query()->create(['nombre' => 'Metropolitana de Santiago']);
        $comuna = Comuna::query()->create(['region_id' => $region->id, 'nombre' => 'Santiago']);

        return [$region, $comuna];
    }

    public function test_creates_propietario_with_user_and_propietario_role(): void
    {
        Role::findOrCreate('propietario', 'web');
        [$region, $comuna] = $this->createRegion();

        $file = $this->file([
            2 => [
                'rut' => '11111111-1',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan.perez@correo.cl',
                'telefono' => '+56 9 1234 5678',
                'direccion' => 'Av. Siempre Viva 123',
                'region' => 'Metropolitana de Santiago',
                'comuna' => 'Santiago',
                'nacionalidad' => 'Chilena',
                'profesion' => 'Ingeniero',
                'estado_civil' => 'Casado',
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->errorCount());

        $propietario = Propietario::query()->where('rut', '11111111-1')->first();

        $this->assertNotNull($propietario);
        $this->assertNotNull($propietario->user_id);
        $this->assertTrue($propietario->user->hasRole('propietario'));
        $this->assertSame($region->id, $propietario->region_id);
        $this->assertSame($comuna->id, $propietario->comuna_id);
        $this->assertSame('juan.perez@correo.cl', $propietario->user->email);
        $this->assertSame('juan.perez@correo.cl', $propietario->email);
    }

    public function test_normalizes_rut_with_dots_and_uppercase_dv(): void
    {
        Role::findOrCreate('propietario', 'web');

        $file = $this->file([
            2 => [
                'rut' => '11.111.111-1',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => null,
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('propietarios', ['rut' => '11111111-1']);
    }

    public function test_updates_existing_propietario_and_syncs_user(): void
    {
        $user = User::factory()->create(['name' => 'Nombre Viejo', 'email' => 'viejo@correo.cl']);
        $propietario = Propietario::query()->create([
            'user_id' => $user->id,
            'nombre' => 'Nombre Viejo',
            'apellido' => 'Apellido Viejo',
            'rut' => '11111111-1',
            'email' => 'viejo@correo.cl',
        ]);

        $file = $this->file([
            2 => [
                'rut' => '11111111-1',
                'nombre' => 'Nombre Nuevo',
                'apellido' => 'Apellido Nuevo',
                'email' => 'nuevo@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => null,
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->created);
        $this->assertDatabaseCount('propietarios', 1);
        $this->assertDatabaseHas('propietarios', [
            'id' => $propietario->id,
            'nombre' => 'Nombre Nuevo',
            'apellido' => 'Apellido Nuevo',
            'email' => 'nuevo@correo.cl',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nombre Nuevo Apellido Nuevo',
            'email' => 'nuevo@correo.cl',
        ]);
    }

    public function test_invalid_rut_is_rejected(): void
    {
        $file = $this->file([
            2 => [
                'rut' => '12345678-9',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => null,
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame('rut', $result->errors[0]['field']);
    }

    public function test_email_conflict_with_existing_user_is_reported(): void
    {
        User::factory()->create(['email' => 'juan@correo.cl']);

        $file = $this->file([
            2 => [
                'rut' => '11111111-1',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => null,
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('juan@correo.cl', $result->errors[0]['message']);
    }

    public function test_unknown_region_is_reported(): void
    {
        $file = $this->file([
            2 => [
                'rut' => '11111111-1',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => 'Region Inexistente',
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('Region Inexistente', $result->errors[0]['message']);
    }

    public function test_comuna_mismatched_with_region_is_reported(): void
    {
        $this->createRegion();

        $file = $this->file([
            2 => [
                'rut' => '11111111-1',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => 'Metropolitana de Santiago',
                'comuna' => 'Providencia',
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        $result = app(PropietarioImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('Providencia', $result->errors[0]['message']);
    }

    public function test_missing_required_header_throws(): void
    {
        $file = new ParsedFile(['rut'], [['row_number' => 2, 'data' => ['rut' => '11111111-1']]]);

        $this->expectException(DomainException::class);

        app(PropietarioImportService::class)->import($file);
    }

    public function test_does_not_create_user_or_propietario_on_row_error(): void
    {
        $file = $this->file([
            2 => [
                'rut' => '12345678-9',
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'email' => 'juan@correo.cl',
                'telefono' => null,
                'direccion' => null,
                'region' => null,
                'comuna' => null,
                'nacionalidad' => null,
                'profesion' => null,
                'estado_civil' => null,
            ],
        ]);

        app(PropietarioImportService::class)->import($file);

        $this->assertDatabaseCount('propietarios', 0);
        $this->assertDatabaseMissing('users', ['email' => 'juan@correo.cl']);
    }
}
