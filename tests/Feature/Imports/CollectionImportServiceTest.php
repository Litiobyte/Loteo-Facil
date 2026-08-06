<?php

namespace Tests\Feature\Imports;

use App\Domain\Collections\Enums\CollectionStatus;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Services\CollectionImportService;
use App\Models\AccountingPeriod;
use App\Models\Collection;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;

class CollectionImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function file(array $rows): ParsedFile
    {
        $data = [];

        foreach ($rows as $rowNumber => $dataRow) {
            $data[] = ['row_number' => $rowNumber, 'data' => $dataRow];
        }

        return new ParsedFile(CollectionImportService::HEADERS, $data);
    }

    private function createPropietario(): Propietario
    {
        $user = User::factory()->create();

        return Propietario::query()->create([
            'user_id' => $user->id,
            'nombre' => 'Juan',
            'apellido' => 'Perez',
            'rut' => '11111111-1',
            'email' => $user->email,
        ]);
    }

    public function test_creates_pending_collection_with_created_by(): void
    {
        $admin = User::factory()->create();
        $propietario = $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '50000',
                'fecha' => '2026-07-15',
                'metodo' => 'transferencia',
                'referencia' => 'REF-2026-001',
                'notas' => 'Pago de cuota',
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file, $admin->id);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->errorCount());

        $collection = Collection::query()->first();

        $this->assertNotNull($collection);
        $this->assertSame($propietario->id, $collection->propietario_id);
        $this->assertSame(50000.0, (float) $collection->amount);
        $this->assertSame('2026-07-15', $collection->collection_date->toDateString());
        $this->assertSame('transferencia', $collection->collection_method->value);
        $this->assertSame($admin->id, $collection->created_by);
        $this->assertSame(CollectionStatus::PendingApplication, $collection->status);
        $this->assertSame(50000.0, (float) $collection->unapplied_amount);
    }

    public function test_defaults_metodo_to_efectivo(): void
    {
        $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => '2026-07-15',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame('efectivo', Collection::query()->first()->collection_method->value);
    }

    public function test_unknown_propietario_is_reported_as_row_error(): void
    {
        $file = $this->file([
            2 => [
                'rut_propietario' => '22222222-2',
                'monto' => '10000',
                'fecha' => '2026-07-15',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('No existe un propietario', $result->errors[0]['message']);
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_closed_period_rows_are_reported_as_errors(): void
    {
        $this->createPropietario();

        AccountingPeriod::factory()->closed()->create([
            'year' => 2026,
            'month' => 6,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => '2026-06-15',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
            3 => [
                'rut_propietario' => '11111111-1',
                'monto' => '20000',
                'fecha' => '2026-07-15',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame(2, $result->errors[0]['row']);
        $this->assertStringContainsString('período contable cerrado', $result->errors[0]['message']);
        $this->assertDatabaseCount('collections', 1);
    }

    public function test_accepts_d_m_y_date_format(): void
    {
        $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => '15/07/2026',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame('2026-07-15', Collection::query()->first()->collection_date->toDateString());
    }

    public function test_accepts_excel_date_serial(): void
    {
        $this->createPropietario();

        $serial = ExcelDate::dateTimeToExcel(Carbon::parse('2026-07-15'));

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => $serial,
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(1, $result->created);
        $this->assertSame('2026-07-15', Collection::query()->first()->collection_date->toDateString());
    }

    public function test_invalid_date_is_reported_as_row_error(): void
    {
        $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => 'no-es-fecha',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('fecha', $result->errors[0]['message']);
    }

    public function test_future_date_is_rejected_by_model(): void
    {
        $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '10000',
                'fecha' => now()->addMonth()->toDateString(),
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_non_positive_monto_is_rejected(): void
    {
        $this->createPropietario();

        $file = $this->file([
            2 => [
                'rut_propietario' => '11111111-1',
                'monto' => '0',
                'fecha' => '2026-07-15',
                'metodo' => null,
                'referencia' => null,
                'notas' => null,
            ],
        ]);

        $result = app(CollectionImportService::class)->import($file);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->errorCount());
        $this->assertSame('monto', $result->errors[0]['field']);
    }

    public function test_missing_required_header_throws(): void
    {
        $file = new ParsedFile(['rut_propietario'], [['row_number' => 2, 'data' => ['rut_propietario' => '11111111-1']]]);

        $this->expectException(DomainException::class);

        app(CollectionImportService::class)->import($file);
    }
}
