<?php

namespace App\Domain\Imports\Services;

use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Models\Collection;
use App\Models\Propietario;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final class CollectionImportService
{
    /**
     * @var list<string>
     */
    public const HEADERS = ['rut_propietario', 'monto', 'fecha', 'metodo', 'referencia', 'notas'];

    public function import(ParsedFile $file, ?int $createdBy = null): ImportResult
    {
        $result = new ImportResult;

        $missing = $file->missingHeaders(self::HEADERS);

        if ($missing !== []) {
            throw new DomainException('El archivo debe contener las columnas: '.implode(', ', $missing).'.');
        }

        foreach ($file->rows as $row) {
            $rowNumber = $row['row_number'];
            $data = $row['data'];

            $validator = Validator::make($data, [
                'rut_propietario' => ['required', new ValidChileanRut],
                'monto' => ['required', 'numeric', 'gt:0'],
                'fecha' => ['required'],
                'metodo' => ['required', Rule::in(array_column(CollectionMethod::cases(), 'value'))],
                'referencia' => ['nullable', 'string', 'max:255'],
                'notas' => ['nullable', 'string'],
            ], [
                'required' => 'El campo :attribute es obligatorio.',
                'numeric' => 'El campo :attribute debe ser un número.',
                'gt' => 'El campo :attribute debe ser mayor que 0.',
                'max' => 'El campo :attribute supera el largo máximo.',
                'in' => 'El campo :attribute tiene un valor no permitido.',
            ], [
                'rut_propietario' => 'rut del propietario',
                'monto' => 'monto',
                'fecha' => 'fecha',
                'metodo' => 'método de pago',
                'referencia' => 'referencia',
                'notas' => 'notas',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                    $result->errors[] = [
                        'row' => $rowNumber,
                        'field' => $field,
                        'message' => $fieldMessages[0],
                    ];
                }

                continue;
            }

            try {
                $this->importRow($result, $data, $rowNumber, $createdBy);
            } catch (Throwable $exception) {
                $result->errors[] = [
                    'row' => $rowNumber,
                    'field' => null,
                    'message' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar la recaudación.',
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string|int|float|null>  $data
     */
    private function importRow(ImportResult $result, array $data, int $rowNumber, ?int $createdBy): void
    {
        $rut = ChileanRut::normalize(trim((string) $data['rut_propietario']));

        $propietario = Propietario::query()->where('rut', $rut)->first();

        if ($propietario === null) {
            throw new DomainException('No existe un propietario con el rut '.$rut.'.');
        }

        $fecha = $this->normalizeDate($data['fecha']);

        if ($fecha === null) {
            throw new DomainException('La fecha "'.$data['fecha'].'" no es una fecha válida. Usa formato AAAA-MM-DD o DD/MM/AAAA.');
        }

        Collection::query()->create([
            'propietario_id' => $propietario->id,
            'amount' => round((float) $data['monto'], 2),
            'collection_date' => $fecha->toDateString(),
            'collection_method' => (string) $data['metodo'],
            'reference' => ($data['referencia'] ?? null) ?: null,
            'notes' => ($data['notas'] ?? null) ?: null,
            'created_by' => $createdBy,
        ]);

        $result->created++;
    }

    private function normalizeDate(mixed $value): ?Carbon
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 20000 || $value > 80000) {
                return null;
            }

            return Carbon::instance(ExcelDate::excelToDateTimeObject($value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date instanceof Carbon && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // formato no coincide, continuar
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
