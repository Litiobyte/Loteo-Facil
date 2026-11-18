<?php

namespace App\Domain\Imports\Services;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Models\DataImport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportFileDownloads
{
    public function __construct(private AdministrativeImportService $imports, private ImportDefinition $definition) {}

    public function template(string $type, string $format, User $actor): Response
    {
        $this->imports->authorize($actor);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $headers = array_values($this->definition->templateHeaders($type));
        $example = $type === 'owners'
            ? ['rut' => '12.345.678-5', 'nombre' => 'Ana', 'apellido' => 'Pérez', 'email' => 'ana@example.cl', 'user_id' => '123 (vacío para crear la cuenta)', 'telefono' => '+56912345678', 'direccion' => 'Camino central 10', 'region_id' => 'Valparaíso', 'comuna_id' => 'Valparaíso', 'nacionalidad' => 'Chilena', 'profesion' => 'Profesora', 'estado_civil' => 'Soltero']
            : ['codigo' => 'LOTE-001', 'metros_cuadrados' => '5000', 'estado' => 'disponible', 'notas' => 'Ejemplo administrativo'];
        if ($type === 'shares') {
            $example = ['rut' => '12.345.678-5', 'quantity' => '2', 'amount' => '1500000.00', 'reference_date' => today()->toDateString(), 'notes' => 'Saldo histórico consolidado'];
        }
        $values = array_map(fn (string $field): string => $example[$field] ?? '', array_keys($this->definition->templateHeaders($type)));
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $values): void {
                $handle = fopen('php://output', 'w');
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $headers, ',', '"', '');
                fputcsv($handle, $values, ',', '"', '');
                fclose($handle);
            }, "plantilla-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = tempnam(sys_get_temp_dir(), 'import-template-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues($headers));
            $writer->addRow(Row::fromValues($values));
            $writer->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return response()->streamDownload(function () use ($path): void {
            try {
                readfile($path);
            } finally {
                unlink($path);
            }
        }, "plantilla-{$type}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function report(DataImport $import, User $actor, bool $errorsOnly = false): StreamedResponse
    {
        $this->imports->authorize($actor, $import);

        return response()->streamDownload(function () use ($import, $errorsOnly): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['fila', 'operacion_prevista', 'resultado', 'errores', 'celdas_originales', 'datos', 'cambios'], ',', '"', '');
            $query = $import->rows()->orderBy('id');
            if ($errorsOnly) {
                $query->where(fn ($query) => $query->where('action', 'error')->orWhere('result', 'error'));
            }
            foreach ($query->cursor() as $row) {
                $cells = [$row->number, $row->action, $row->result, implode(' | ', $row->errors ?? []), json_encode($row->raw, JSON_UNESCAPED_UNICODE), json_encode($row->data, JSON_UNESCAPED_UNICODE), json_encode($row->changes, JSON_UNESCAPED_UNICODE)];
                $cells = array_map(function (mixed $value): string {
                    $value = (string) $value;

                    return preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
                }, $cells);
                fputcsv($handle, $cells, ',', '"', '');
            }
            fclose($handle);
        }, "importacion-{$import->id}".($errorsOnly ? '-errores' : '').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function original(DataImport $import, User $actor): StreamedResponse
    {
        $this->imports->authorize($actor, $import);

        return Storage::disk('local')->download($import->path, $import->original_name);
    }

    public function eligibleAccounts(User $actor): StreamedResponse
    {
        $this->imports->authorize($actor);

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['user_id', 'nombre_cuenta', 'email_cuenta'], ',', '"', '');
            $query = app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query());
            foreach ($query->orderBy('id')->cursor() as $user) {
                $values = array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, [$user->id, $user->name, $user->email]);
                fputcsv($handle, $values, ',', '"', '');
            }
            fclose($handle);
        }, 'cuentas-disponibles.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
