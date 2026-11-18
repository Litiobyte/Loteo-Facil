<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\AdministrativeImportService;
use App\Domain\Imports\Services\ImportDefinition;
use App\Domain\Imports\Services\ImportFileDownloads;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Models\CommunitySetting;
use App\Models\Comuna;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\ShareContribution;
use App\Models\User;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdministrativeImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdministrativeImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $this->seed(RegionSeeder::class);

        $this->service = app(AdministrativeImportService::class);
    }

    public function test_non_admin_cannot_start_preview_or_execute(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('propietario');

        $file = $this->makeCsv(['RUT', 'Nombre'], [['12.345.678-5', 'Ana']]);

        $this->expectException(HttpException::class);
        $this->service->start($file, 'owners', $actor);
    }

    public function test_start_validates_type_and_etapa_requirements(): void
    {
        $admin = $this->adminUser();
        $file = $this->makeCsv(['Codigo', 'Metros'], [['L-1', '5000']]);

        $this->expectException(ValidationException::class);
        $this->service->start($file, 'lots', $admin);
    }

    public function test_start_rejects_unsupported_type(): void
    {
        $admin = $this->adminUser();
        $file = $this->makeCsv(['RUT'], [['12.345.678-5']]);

        $this->expectException(ValidationException::class);
        $this->service->start($file, 'unknown', $admin);
    }

    public function test_import_creates_owners_with_region_and_comuna_by_name(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();
        $region = Region::query()->where('nombre', 'like', '%Valpara%')->first() ?? Region::query()->create(['nombre' => 'Valparaíso']);
        $comuna = Comuna::query()->where('region_id', $region->id)->where('nombre', 'Valparaíso')->first() ?? Comuna::query()->create(['region_id' => $region->id, 'nombre' => 'Valparaíso']);
        $rut = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID', 'region', 'comuna'],
            [[$rut, 'Ana', 'Torres', 'ana.loc@example.com', (string) $user->id, 'region valparaiso', 'VALPARAISO']]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $import = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4, 'region_id' => 5, 'comuna_id' => 6], 'skip', $admin);
        $this->assertSame(0, $import->error_count);

        $executed = $this->service->execute($import, $admin, true);
        $this->assertSame('completed', $executed->status);
        $this->assertDatabaseHas('propietarios', ['rut' => $rut, 'region_id' => $region->id, 'comuna_id' => $comuna->id]);
    }

    public function test_import_rejects_rows_with_mismatched_comuna_region(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();
        $metropolitana = Region::query()->where('nombre', 'like', '%Metropolitana%')->first() ?? Region::query()->create(['nombre' => 'Metropolitana de Santiago']);
        $santiago = Comuna::query()->where('region_id', $metropolitana->id)->where('nombre', 'Santiago')->first() ?? Comuna::query()->create(['region_id' => $metropolitana->id, 'nombre' => 'Santiago']);
        $valparaiso = Region::query()->where('nombre', 'like', '%Valpara%')->first() ?? Region::query()->create(['nombre' => 'Valparaíso']);
        $rut = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID', 'region', 'comuna'],
            [[$rut, 'Ana', 'Torres', 'ana.mala@example.com', (string) $user->id, 'valparaiso', $santiago->nombre]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $import = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4, 'region_id' => 5, 'comuna_id' => 6], 'skip', $admin);
        $this->assertSame(1, $import->error_count);
        $this->assertStringContainsString('no pertenece a la región', $import->rows()->first()->errors[0]);
        $this->assertStringContainsString($valparaiso->nombre, $import->rows()->first()->errors[0]);

        $executed = $this->service->execute($import, $admin, true);
        $this->assertSame('completed_with_errors', $executed->status);
        $this->assertDatabaseMissing('propietarios', ['rut' => $rut]);
    }

    public function test_template_uses_region_and_comuna_names_without_ids(): void
    {
        $csv = TestResponse::fromBaseResponse(
            app(ImportFileDownloads::class)->template('owners', 'csv', $this->adminUser())
        )->streamedContent();

        $this->assertStringContainsString('region', $csv);
        $this->assertStringContainsString('comuna', $csv);
        $this->assertStringNotContainsString('region_id', $csv);
        $this->assertStringNotContainsString('comuna_id', $csv);
        $this->assertStringContainsString('Valparaíso', $csv);

        $definition = new ImportDefinition;
        $mapping = $definition->autoMap('owners', ['RUT', 'Nombre', 'Apellido', 'Email', 'region', 'comuna']);
        $this->assertSame(4, $mapping['region_id']);
        $this->assertSame(5, $mapping['comuna_id']);
    }

    public function test_owners_create_from_csv(): void
    {
        $admin = $this->adminUser();
        $userA = $this->eligibleOwnerUser();
        $userB = $this->eligibleOwnerUser();

        $rutA = $this->validRut();
        $rutB = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID', 'Telefono'],
            [
                [$rutA, 'Ana', 'Torres', 'ana@example.com', (string) $userA->id, '+56911111111'],
                [$rutB, 'Luis', 'Rojas', 'luis@example.com', (string) $userB->id, '+56922222222'],
            ]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $mapping = ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4, 'telefono' => 5];
        $import = $this->service->preview($import, $mapping, 'skip', $admin);

        $this->assertSame('preview', $import->status);
        $this->assertSame(2, $import->created_count);

        $executed = $this->service->execute($import, $admin, true);

        $this->assertSame('completed', $executed->status);
        $this->assertDatabaseHas('propietarios', ['rut' => $rutA, 'user_id' => $userA->id, 'email' => 'ana@example.com']);
        $this->assertDatabaseHas('propietarios', ['rut' => $rutB, 'user_id' => $userB->id, 'email' => 'luis@example.com']);
    }

    public function test_owners_update_and_skip_modes(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();
        $propietario = Propietario::factory()->create([
            'user_id' => $user->id,
            'rut' => $this->validRut(),
            'telefono' => '+56900000000',
        ]);

        $file = $this->makeCsv(
            ['RUT', 'Telefono'],
            [[$propietario->rut, '+56999999999']]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $mapping = ['rut' => 0, 'telefono' => 1];

        $skipPreview = $this->service->preview($import, $mapping, 'skip', $admin);
        $this->assertSame(1, $skipPreview->skipped_count);
        $this->assertSame(0, $skipPreview->updated_count);

        $updatePreview = $this->service->preview($import->refresh(), $mapping, 'update', $admin);
        $this->assertSame(0, $updatePreview->skipped_count);
        $this->assertSame(1, $updatePreview->updated_count);

        $this->service->execute($updatePreview, $admin, true);

        $this->assertSame('+56999999999', $propietario->fresh()->telefono);
    }

    public function test_ambiguous_row_can_be_corrected_during_preview(): void
    {
        $admin = $this->adminUser();
        $rut = $this->validRut();
        Propietario::factory()->create(['rut' => '98765432-1', 'email' => 'b@example.com']);

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email'],
            [[$rut, 'Ana', 'Torres', 'b@example.com']]
        );
        $import = $this->service->start($file, 'owners', $admin);
        $import = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3], 'skip', $admin);

        $this->assertSame(1, $import->error_count);
        $this->assertStringContainsString('correo pertenece a otro RUT', implode(' ', $import->rows()->firstOrFail()->errors));

        $row = $import->rows()->firstOrFail();
        $corrected = $this->service->correctRow($import, $row->id, ['email' => 'NUEVO@example.com', 'desconocido' => 'x'], $admin);

        $this->assertSame(0, $corrected->error_count);
        $this->assertSame(1, $corrected->created_count);
        $this->assertSame(['email' => 'NUEVO@example.com'], $corrected->rows()->firstOrFail()->overrides);
        $this->assertSame('nuevo@example.com', $corrected->rows()->firstOrFail()->data['email']);

        $executed = $this->service->execute($corrected, $admin, true);

        $this->assertSame('completed', $executed->status);
        $this->assertDatabaseHas('propietarios', ['rut' => $rut, 'email' => 'nuevo@example.com']);
    }

    public function test_corrections_require_preview_and_the_importing_admin(): void
    {
        $admin = $this->adminUser();
        $rut = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email'],
            [[$rut, 'Ana', 'Torres', 'ana@example.com']]
        );
        $import = $this->service->start($file, 'owners', $admin);
        $row = $import->rows()->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->correctRow($import, $row->id, ['email' => 'otra@example.com'], $admin);
    }

    public function test_corrections_reject_admins_other_than_the_import_owner(): void
    {
        $admin = $this->adminUser();
        $rut = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email'],
            [[$rut, 'Ana', 'Torres', 'ana@example.com']]
        );
        $import = $this->service->start($file, 'owners', $admin);
        $import = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3], 'skip', $admin);
        $row = $import->rows()->firstOrFail();

        $this->expectException(HttpException::class);
        $this->service->correctRow($import, $row->id, ['email' => 'otra@example.com'], $this->eligibleOwnerUser());
    }

    public function test_owners_blanks_do_not_overwrite_existing_values(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();
        $propietario = Propietario::factory()->create([
            'user_id' => $user->id,
            'rut' => $this->validRut(),
            'telefono' => '+56900000000',
        ]);

        $file = $this->makeCsv(['RUT', 'Telefono'], [[$propietario->rut, '']]);
        $import = $this->service->start($file, 'owners', $admin);

        $preview = $this->service->preview($import, ['rut' => 0, 'telefono' => 1], 'update', $admin);

        $this->assertSame(1, $preview->skipped_count);

        $this->service->execute($preview, $admin, true);

        $this->assertSame('+56900000000', $propietario->fresh()->telefono);
        $this->assertSame($propietario->email, $propietario->fresh()->email);
    }

    public function test_owners_rejects_ineligible_user_and_conflicts(): void
    {
        $admin = $this->adminUser();
        $eligibleUser = $this->eligibleOwnerUser();
        $userWithoutRole = User::factory()->create();
        $alreadyLinkedUser = $this->eligibleOwnerUser();
        Propietario::factory()->create(['user_id' => $alreadyLinkedUser->id]);

        $existing = Propietario::factory()->create([
            'rut' => $this->validRut(),
            'email' => 'existente@example.com',
        ]);

        $conflictRut = $this->validRut();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [
                [$this->validRut(), 'A', 'B', 'a@example.com', (string) $userWithoutRole->id],
                [$this->validRut(), 'C', 'D', 'b@example.com', (string) $alreadyLinkedUser->id],
                [$conflictRut, 'E', 'F', $existing->email, (string) $eligibleUser->id],
            ]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $this->assertSame(3, $preview->error_count);
        $this->assertSame(0, $preview->created_count);

        $executed = $this->service->execute($preview, $admin, true);
        $this->assertSame('completed_with_errors', $executed->status);
        $this->assertDatabaseMissing('propietarios', ['rut' => $conflictRut]);
    }

    public function test_owners_rejects_ambiguous_identifier_matches(): void
    {
        $admin = $this->adminUser();

        $propietarioA = Propietario::factory()->create([
            'rut' => $this->validRut(),
            'email' => 'a@conflicto.cl',
        ]);
        $propietarioB = Propietario::factory()->create([
            'rut' => $this->validRut(),
            'email' => 'b@conflicto.cl',
        ]);

        $file = $this->makeCsv(
            ['RUT', 'Email'],
            [[$propietarioA->rut, $propietarioB->email]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'email' => 1], 'update', $admin);

        $this->assertSame(1, $preview->error_count);
    }

    public function test_lots_create_update_notes_and_reject_critical_changes(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();

        $existingUpdate = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'codigo' => 'L-UPDATE',
            'metros_cuadrados' => 5000,
            'estado' => 'disponible',
            'notas' => 'Nota original',
        ]);
        $existingCritical = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'codigo' => 'L-CRITICAL',
            'metros_cuadrados' => 5000,
            'estado' => 'disponible',
            'notas' => 'Nota critica',
        ]);

        $file = $this->makeCsv(
            ['Codigo', 'Metros', 'Estado', 'Notas'],
            [
                ['L-NEW', '10000', 'disponible', 'Lote nuevo'],
                ['L-UPDATE', '', '', 'Nota actualizada'],
                ['L-CRITICAL', '9999', 'vendido', 'No debe importar'],
            ]
        );

        $import = $this->service->start($file, 'lots', $admin, $etapa->id);
        $mapping = ['codigo' => 0, 'metros_cuadrados' => 1, 'estado' => 2, 'notas' => 3];
        $preview = $this->service->preview($import, $mapping, 'update', $admin);

        $this->assertSame(1, $preview->created_count);
        $this->assertSame(1, $preview->updated_count);
        $this->assertSame(1, $preview->error_count);

        $this->service->execute($preview, $admin, true);

        $this->assertDatabaseHas('lotes', ['codigo' => 'L-NEW', 'etapa_id' => $etapa->id, 'metros_cuadrados' => 10000]);
        $this->assertSame('Nota actualizada', $existingUpdate->fresh()->notas);
        $this->assertSame(5000, $existingUpdate->fresh()->metros_cuadrados);
        $this->assertSame('disponible', $existingUpdate->fresh()->estado);
        $this->assertSame('Nota critica', $existingCritical->fresh()->notas);
        $this->assertSame(5000, $existingCritical->fresh()->metros_cuadrados);
        $this->assertSame('disponible', $existingCritical->fresh()->estado);
    }

    public function test_lots_rejects_code_existing_in_another_etapa(): void
    {
        $admin = $this->adminUser();
        $etapaA = Etapa::factory()->create();
        $etapaB = Etapa::factory()->create();

        Lote::factory()->create(['etapa_id' => $etapaA->id, 'codigo' => 'L-SHARED']);

        $file = $this->makeCsv(
            ['Codigo', 'Metros', 'Estado'],
            [['L-SHARED', '8000', 'disponible']]
        );

        $import = $this->service->start($file, 'lots', $admin, $etapaB->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'metros_cuadrados' => 1, 'estado' => 2], 'skip', $admin);

        $this->assertSame(1, $preview->error_count);
        $this->assertSame(0, $preview->created_count);
    }

    public function test_preview_does_not_modify_database(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$this->validRut(), 'Nuevo', 'Dueño', 'nuevo@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $this->assertDatabaseCount('propietarios', 0);
        $this->assertSame('preview', $import->fresh()->status);
    }

    public function test_execute_requires_confirmation_and_is_idempotent(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$this->validRut(), 'Pepe', 'Lopez', 'pepe@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $this->expectException(ValidationException::class);
        $this->service->execute($preview, $admin, false);
    }

    public function test_execute_is_idempotent_on_repeated_calls(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$this->validRut(), 'Pepe', 'Lopez', 'pepe@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $first = $this->service->execute($preview, $admin, true);
        $second = $this->service->execute($first, $admin, true);

        $this->assertSame('completed', $second->status);
        $this->assertSame($first->created_count, $second->created_count);
        $this->assertDatabaseCount('propietarios', 1);
    }

    public function test_execute_detects_obsolete_target_fingerprint(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'codigo' => 'L-OBS',
            'notas' => 'Original',
        ]);

        $file = $this->makeCsv(
            ['Codigo', 'Notas'],
            [['L-OBS', 'Cambio planificado']]
        );

        $import = $this->service->start($file, 'lots', $admin, $etapa->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'notas' => 1], 'update', $admin);

        $lote->update(['notas' => 'Modificado externamente']);

        $executed = $this->service->execute($preview, $admin, true);

        $this->assertSame('completed_with_errors', $executed->status);
        $this->assertSame('Modificado externamente', $lote->fresh()->notas);
    }

    public function test_execute_revalidates_owner_user_eligibility(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$this->validRut(), 'Pepe', 'Lopez', 'pepe@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $user->removeRole('propietario');

        $executed = $this->service->execute($preview, $admin, true);

        $this->assertSame('completed_with_errors', $executed->status);
        $this->assertDatabaseCount('propietarios', 0);
    }

    public function test_only_owner_can_execute_import(): void
    {
        $owner = $this->adminUser();
        $other = $this->adminUser();
        $user = $this->eligibleOwnerUser();

        $file = $this->makeCsv(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$this->validRut(), 'Pepe', 'Lopez', 'pepe@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $owner);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $owner);

        $this->expectException(HttpException::class);
        $this->service->execute($preview, $other, true);
    }

    public function test_xlsx_import_creates_owners(): void
    {
        $admin = $this->adminUser();
        $user = $this->eligibleOwnerUser();
        $rut = $this->validRut();

        $file = $this->makeXlsx(
            ['RUT', 'Nombre', 'Apellido', 'Email', 'UserID'],
            [[$rut, 'Xlsx', 'Owner', 'xlsx@example.com', (string) $user->id]]
        );

        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);

        $this->assertSame(1, $preview->created_count);

        $this->service->execute($preview, $admin, true);

        $this->assertDatabaseHas('propietarios', ['rut' => $rut, 'email' => 'xlsx@example.com']);
    }

    public function test_csv_semicolon_delimiter_is_supported(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();

        $file = $this->makeCsv(
            ['Codigo', 'Metros', 'Estado'],
            [['L-SEMI', '7500', 'disponible']],
            ';'
        );

        $import = $this->service->start($file, 'lots', $admin, $etapa->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'metros_cuadrados' => 1, 'estado' => 2], 'skip', $admin);

        $this->assertSame(1, $preview->created_count);

        $this->service->execute($preview, $admin, true);

        $this->assertDatabaseHas('lotes', ['codigo' => 'L-SEMI', 'etapa_id' => $etapa->id, 'metros_cuadrados' => 7500]);
    }

    public function test_file_reader_rejects_more_than_max_rows(): void
    {
        $admin = $this->adminUser();
        $rows = [];
        for ($i = 1; $i <= 2001; $i++) {
            $rows[] = ['L-'.$i, '1000', 'disponible'];
        }

        $file = $this->makeCsv(['Codigo', 'Metros', 'Estado'], $rows);

        $this->expectException(ValidationException::class);
        $this->service->start($file, 'lots', $admin, Etapa::factory()->create()->id);
    }

    public function test_start_throws_validation_exception_when_store_fails(): void
    {
        $admin = $this->adminUser();
        $name = 'owners.csv';
        $path = Storage::disk('local')->path('tmp/'.$name);
        Storage::disk('local')->put('tmp/'.$name, "RUT,Nombre,Apellido,Email,UserID\n".$this->validRut().',Pepe,Lopez,pepe@example.com,1');

        $file = new class($path, $name, 'text/csv', null, true) extends UploadedFile
        {
            public function store($path = '', $options = []): false
            {
                return false;
            }
        };

        $this->expectException(ValidationException::class);

        try {
            $this->service->start($file, 'owners', $admin);
        } finally {
            $this->assertDatabaseCount('data_imports', 0);
            $this->assertDatabaseCount('data_import_rows', 0);
        }
    }

    public function test_lots_update_notes_preserves_zero_surface_when_not_imported(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'codigo' => 'L-ZERO',
            'metros_cuadrados' => 0,
            'estado' => 'disponible',
            'notas' => 'Original',
        ]);

        $file = $this->makeCsv(['Codigo', 'Notas'], [['L-ZERO', 'Nota actualizada']]);
        $import = $this->service->start($file, 'lots', $admin, $etapa->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'notas' => 1], 'update', $admin);

        $this->assertSame(1, $preview->updated_count);
        $this->assertSame(0, $preview->error_count);

        $this->service->execute($preview, $admin, true);

        $fresh = $lote->fresh();
        $this->assertSame('Nota actualizada', $fresh->notas);
        $this->assertSame(0, $fresh->metros_cuadrados);
        $this->assertSame('disponible', $fresh->estado);
    }

    public function test_lots_skip_mode_does_not_update_notes(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();
        $lote = Lote::factory()->create([
            'etapa_id' => $etapa->id,
            'codigo' => 'L-SKIP',
            'notas' => 'Nota original',
        ]);

        $file = $this->makeCsv(['Codigo', 'Notas'], [['L-SKIP', 'Nota diferente']]);
        $import = $this->service->start($file, 'lots', $admin, $etapa->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'notas' => 1], 'skip', $admin);

        $this->assertSame(1, $preview->skipped_count);
        $this->assertSame(0, $preview->updated_count);

        $this->service->execute($preview, $admin, true);

        $this->assertSame('Nota original', $lote->fresh()->notas);
    }

    public function test_case_variant_lot_codes_second_import_fails_when_target_appears(): void
    {
        $admin = $this->adminUser();
        $etapa = Etapa::factory()->create();

        $fileA = $this->makeCsv(['Codigo', 'Metros', 'Estado'], [['L-ABC', '1000', 'disponible']]);
        $fileB = $this->makeCsv(['Codigo', 'Metros', 'Estado'], [['l-abc', '1000', 'disponible']]);

        $importA = $this->service->start($fileA, 'lots', $admin, $etapa->id);
        $importB = $this->service->start($fileB, 'lots', $admin, $etapa->id);

        $previewA = $this->service->preview($importA, ['codigo' => 0, 'metros_cuadrados' => 1, 'estado' => 2], 'skip', $admin);
        $previewB = $this->service->preview($importB, ['codigo' => 0, 'metros_cuadrados' => 1, 'estado' => 2], 'skip', $admin);

        $this->assertSame(1, $previewA->created_count);
        $this->assertSame(1, $previewB->created_count);

        $this->service->execute($previewA, $admin, true);

        $this->assertDatabaseHas('lotes', ['codigo' => 'L-ABC', 'etapa_id' => $etapa->id]);

        $executedB = $this->service->execute($previewB, $admin, true);

        $this->assertSame('completed_with_errors', $executedB->status);
        $this->assertSame(0, $executedB->created_count);
        $this->assertSame(1, $executedB->error_count);
    }

    public function test_duplicate_ruts_are_normalized_and_all_conflicting_file_rows_are_rejected(): void
    {
        $admin = $this->adminUser();
        $first = $this->eligibleOwnerUser();
        $second = $this->eligibleOwnerUser();
        $file = $this->makeCsv(['rut', 'nombre', 'apellido', 'email', 'user_id'], [
            ['12.345.678-5', 'Ana', 'Pérez', 'ana@example.cl', $first->id],
            ['123456785', 'Otra', 'Persona', 'otra@example.cl', $second->id],
        ]);
        $import = $this->service->start($file, 'owners', $admin);
        $preview = $this->service->preview($import, ['rut' => 0, 'nombre' => 1, 'apellido' => 2, 'email' => 3, 'user_id' => 4], 'skip', $admin);
        $this->assertSame(2, $preview->error_count);
        $this->assertSame(['error', 'error'], $preview->rows()->orderBy('number')->pluck('action')->all());
        $this->assertStringContainsString('duplicado dentro del archivo', implode(' ', $preview->rows()->first()->errors));
        $this->service->execute($preview, $admin, true);
        $this->assertDatabaseCount('propietarios', 0);
    }

    public function test_duplicate_lot_codes_in_file_are_rejected_and_reimporting_existing_data_is_safe(): void
    {
        $admin = $this->adminUser();
        $stage = Etapa::factory()->create();
        $file = $this->makeCsv(['codigo', 'metros_cuadrados'], [['DUP-001', 5000], ['dup-001', 6000]]);
        $import = $this->service->start($file, 'lots', $admin, $stage->id);
        $preview = $this->service->preview($import, ['codigo' => 0, 'metros_cuadrados' => 1], 'skip', $admin);
        $this->assertSame(2, $preview->error_count);
        $this->service->execute($preview, $admin, true);
        $this->assertDatabaseMissing('lotes', ['codigo' => 'DUP-001']);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $file = $this->makeCsv(['codigo', 'metros_cuadrados'], [['SAFE-001', 5000]]);
            $import = $this->service->start($file, 'lots', $admin, $stage->id);
            $preview = $this->service->preview($import, ['codigo' => 0, 'metros_cuadrados' => 1], 'skip', $admin);
            $result = $this->service->execute($preview, $admin, true);
            $this->assertSame($attempt === 0 ? 1 : 0, $result->created_count);
            $this->assertSame($attempt === 0 ? 0 : 1, $result->skipped_count);
        }
        $this->assertSame(1, Lote::query()->where('codigo', 'SAFE-001')->count());
    }

    public function test_administrative_updates_preserve_assignments_shares_contributions_and_financial_history(): void
    {
        $admin = $this->adminUser();
        CommunitySetting::current()->update(['mode' => 'mixed']);
        $owner = Propietario::factory()->create(['rut' => '12345678-5']);
        $stage = Etapa::factory()->create();
        $lot = Lote::factory()->create(['etapa_id' => $stage->id, 'codigo' => 'PRESERVE-001']);
        $owner->lotes()->attach($lot, ['assigned_at' => now(), 'status' => 'active']);
        $holding = app(ShareHoldingService::class)->acquire($owner, '10', today()->toDateString(), 'import-fixture', 'Tenencia previa', $admin);
        ShareContribution::query()->create([
            'community_setting_id' => 1, 'propietario_id' => $owner->id, 'share_holding_id' => $holding->id,
            'created_by' => $admin->id, 'type' => 'acquisition', 'quantity' => 10, 'amount' => 150000,
            'reference_date' => today(), 'operation_key' => 'prior-contribution', 'context' => [],
        ]);
        PartnerCharge::factory()->create(['propietario_id' => $owner->id, 'distribution_snapshot' => ['original' => true]]);
        $tables = ['lote_propietario', 'share_holdings', 'share_movements', 'share_contributions', 'partner_charges', 'expenses', 'collections', 'collection_allocations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $file = $this->makeCsv(['rut', 'telefono', 'quantity', 'amount'], [[$owner->rut, '+56911112222', '999', '999999999']]);
        $import = $this->service->start($file, 'owners', $admin);
        $this->service->execute($this->service->preview($import, ['rut' => 0, 'telefono' => 1], 'update', $admin), $admin, true);
        $file = $this->makeCsv(['codigo', 'notas', 'propietario_id'], [[$lot->codigo, 'Nota nueva', '99999']]);
        $import = $this->service->start($file, 'lots', $admin, $stage->id);
        $this->service->execute($this->service->preview($import, ['codigo' => 0, 'notas' => 1], 'update', $admin), $admin, true);
        $this->assertSame('+56911112222', $owner->fresh()->telefono);
        $this->assertSame('Nota nueva', $lot->fresh()->notas);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), "Se modificó {$table}");
        }
    }

    public function test_mapping_rejects_non_administrative_fields(): void
    {
        $admin = $this->adminUser();
        $stage = Etapa::factory()->create();
        $file = $this->makeCsv(['codigo', 'metros_cuadrados', 'valor_lote'], [['REJECT-001', 5000, 99999]]);
        $import = $this->service->start($file, 'lots', $admin, $stage->id);
        $this->expectException(ValidationException::class);
        $this->service->preview($import, ['codigo' => 0, 'metros_cuadrados' => 1, 'valor_lote' => 2], 'update', $admin);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function eligibleOwnerUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('propietario');

        return $user;
    }

    private function makeCsv(array $headers, array $rows, string $delimiter = ','): UploadedFile
    {
        $lines = [implode($delimiter, $headers)];
        foreach ($rows as $row) {
            $lines[] = implode($delimiter, array_map(fn (mixed $value): string => '"'.str_replace('"', '""', (string) $value).'"', $row));
        }
        $content = implode("\n", $lines);
        $name = uniqid('import_', true).'.csv';
        Storage::disk('local')->put('tmp/'.$name, $content);

        return new UploadedFile(Storage::disk('local')->path('tmp/'.$name), $name, 'text/csv', null, true);
    }

    private function makeXlsx(array $headers, array $rows): UploadedFile
    {
        $name = uniqid('import_', true).'.xlsx';
        $path = Storage::disk('local')->path('tmp/'.$name);
        Storage::disk('local')->makeDirectory('tmp');

        $writer = new XlsxWriter(new XlsxOptions);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function validRut(): string
    {
        $number = random_int(5_000_000, 30_000_000);
        $sum = 0;
        $factor = 2;
        foreach (array_reverse(str_split((string) $number)) as $digit) {
            $sum += (int) $digit * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $rest = 11 - ($sum % 11);
        $dv = match ($rest) {
            11 => '0',
            10 => 'K',
            default => (string) $rest,
        };

        return $number.'-'.$dv;
    }
}
