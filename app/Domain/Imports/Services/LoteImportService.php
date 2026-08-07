<?php

namespace App\Domain\Imports\Services;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Support\StringNormalizer;
use App\Models\Etapa;
use App\Models\Lote;
use DomainException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class LoteImportService
{
    /**
     * @var list<string>
     */
    public const HEADERS = ['codigo', 'estado', 'metros_cuadrados', 'etapa', 'valor_lote', 'notas'];

    /**
     * @var SupportCollection<int, array{id: int, numero: int, nombre: string}>|null
     */
    private ?SupportCollection $etapasCache = null;

    public function import(ParsedFile $file): ImportResult
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
                'codigo' => ['required', 'string', 'max:255'],
                'estado' => ['required', Rule::in(['disponible', 'reservado', 'vendido'])],
                'metros_cuadrados' => ['required', 'integer', 'gt:0', 'max:2147483647'],
                'etapa' => ['required', 'string', 'max:255'],
                'valor_lote' => ['required', 'integer', 'min:0', 'max:2147483647'],
                'notas' => ['nullable', 'string', 'max:1000'],
            ], [
                'required' => 'El campo :attribute es obligatorio.',
                'integer' => 'El campo :attribute debe ser un número entero.',
                'gt' => 'El campo :attribute debe ser mayor que 0.',
                'min' => 'El campo :attribute no puede ser negativo.',
                'max' => 'El campo :attribute supera el largo máximo.',
                'in' => 'El campo :attribute tiene un valor no permitido.',
            ], [
                'codigo' => 'codigo',
                'estado' => 'estado',
                'metros_cuadrados' => 'metros cuadrados',
                'etapa' => 'etapa',
                'valor_lote' => 'valor del lote',
                'notas' => 'notas',
            ]);

            if ($validator->fails()) {
                $this->pushValidationErrors($result, $rowNumber, $validator->errors()->messages());

                continue;
            }

            try {
                $etapaId = $this->resolveEtapa($data['etapa'] ?? null);

                $lote = Lote::query()->where('codigo', trim((string) $data['codigo']))->first();

                $attributes = [
                    'codigo' => trim((string) $data['codigo']),
                    'estado' => (string) $data['estado'],
                    'metros_cuadrados' => (int) $data['metros_cuadrados'],
                    'valor_lote' => (int) $data['valor_lote'],
                    'notas' => ($data['notas'] ?? null) ?: null,
                    'etapa_id' => $etapaId,
                ];

                if ($lote) {
                    $lote->update($attributes);
                    $result->updated++;
                } else {
                    Lote::query()->create($attributes);
                    $result->created++;
                }
            } catch (Throwable $exception) {
                $result->errors[] = [
                    'row' => $rowNumber,
                    'field' => null,
                    'message' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar el lote.',
                ];
            }
        }

        return $result;
    }

    private function resolveEtapa(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (ctype_digit($value)) {
            $etapa = $this->etapas()->firstWhere('numero', (int) $value);

            if ($etapa) {
                return $etapa['id'];
            }

            throw new DomainException('La etapa número '.$value.' no existe.');
        }

        $folded = StringNormalizer::fold($value);
        $etapa = $this->etapas()->first(fn (array $etapa): bool => StringNormalizer::fold($etapa['nombre']) === $folded);

        if ($etapa === null) {
            throw new DomainException('La etapa "'.$value.'" no existe.');
        }

        return $etapa['id'];
    }

    /**
     * @return SupportCollection<int, array{id: int, numero: int, nombre: string}>
     */
    private function etapas(): SupportCollection
    {
        return $this->etapasCache ??= Etapa::query()
            ->get(['id', 'numero', 'nombre'])
            ->map(fn (Etapa $etapa): array => [
                'id' => $etapa->id,
                'numero' => $etapa->numero,
                'nombre' => $etapa->nombre,
            ]);
    }

    /**
     * @param  array<string, list<string>>  $messages
     */
    private function pushValidationErrors(ImportResult $result, int $rowNumber, array $messages): void
    {
        foreach ($messages as $field => $fieldMessages) {
            $result->errors[] = [
                'row' => $rowNumber,
                'field' => $field,
                'message' => $fieldMessages[0],
            ];
        }
    }
}
