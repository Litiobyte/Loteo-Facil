<?php

namespace App\Domain\Imports\Services;

use App\Models\Comuna;
use App\Models\Region;
use Illuminate\Support\Str;

/**
 * Resuelve región y comuna de una fila de importación a sus IDs.
 * Acepta texto por nombre (tokens, sin acentos ni mayúsculas) o ID numérico heredado.
 * Los catálogos se cargan una sola vez por instancia: sin consultas por fila.
 */
class OwnerLocationResolver
{
    /** @var array<int, string> */
    private array $regions;

    /** @var array<int, array<int, string>> */
    private array $comunasByRegion;

    /** @var array<int, int> ID de región por comuna. */
    private array $comunaRegion;

    /** @var array<int, string> */
    private array $comunas;

    public function __construct()
    {
        $this->regions = Region::query()->pluck('nombre', 'id')->all();
        $comunas = Comuna::query()->get(['id', 'nombre', 'region_id']);
        $this->comunas = $comunas->pluck('nombre', 'id')->all();
        $this->comunaRegion = $comunas->pluck('region_id', 'id')->all();
        $this->comunasByRegion = $comunas->groupBy('region_id')->map(fn ($group): array => $group->pluck('nombre', 'id')->all())->all();
    }

    /**
     * Reemplaza los textos de región/comuna por sus IDs y devuelve los errores de la fila.
     *
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, errors: array<int, string>}
     */
    public function resolve(array $data): array
    {
        $errors = [];
        foreach (['region_id', 'comuna_id'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && trim((string) $value) === '') {
                unset($data[$field]);
            }
        }
        [$regionId, $regionError] = $this->resolveRegion($data);
        $errors = [...$errors, $regionError];
        [$comunaId, $comunaError] = $this->resolveComuna($data, $regionId);
        $errors = [...$errors, $comunaError];
        if ($regionId !== null) {
            $data['region_id'] = $regionId;
        } elseif ($regionError !== null) {
            unset($data['region_id']);
        }
        if ($comunaId !== null) {
            $data['comuna_id'] = $comunaId;
        } elseif ($comunaError !== null) {
            unset($data['comuna_id']);
        }

        return ['data' => $data, 'errors' => array_values(array_filter($errors))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveRegion(array $data): array
    {
        $value = $data['region_id'] ?? null;
        if ($value === null || trim((string) $value) === '') {
            return [null, null];
        }

        return $this->resolveCatalogValue((string) $value, $this->regions, 'Región');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveComuna(array $data, ?int $regionId): array
    {
        $value = $data['comuna_id'] ?? null;
        if ($value === null || trim((string) $value) === '') {
            return [null, null];
        }
        [$comunaId, $error] = $this->resolveCatalogValue((string) $value, $this->comunas, 'Comuna');
        if ($comunaId !== null && $regionId !== null) {
            $catalogRegionId = (int) ($this->comunaRegion[$comunaId] ?? 0);
            if ($catalogRegionId !== $regionId) {
                return [null, 'La comuna "'.$this->comunas[$comunaId].'" no pertenece a la región "'.$this->regions[$regionId].'".'];
            }
        }

        return [$comunaId, $error];
    }

    /**
     * @param  array<int, string>  $catalog
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveCatalogValue(string $value, array $catalog, string $label): array
    {
        $trimmed = trim($value);
        if (ctype_digit($trimmed)) {
            $id = (int) $trimmed;
            if (array_key_exists($id, $catalog)) {
                return [$id, null];
            }

            return [null, $label.' "'.$trimmed.'" no existe.'];
        }
        $candidates = $this->matchName($trimmed, $catalog);
        if (count($candidates) === 1) {
            return [$candidates[0], null];
        }
        if (count($candidates) > 1) {
            $names = implode(', ', array_map(fn (int $id): string => $catalog[$id], $candidates));

            return [null, $label.' ambigua "'.$trimmed.'". Coincidencias: '.$names.'.'];
        }

        return [null, $label.' "'.$trimmed.'" no encontrada.'];
    }

    /**
     * Coincidencia por tokens: todos los tokens de la entrada deben existir como
     * palabras completas del nombre objetivo (sin acentos ni mayúsculas).
     *
     * @param  array<int, string>  $catalog
     * @return array<int, int>
     */
    public function matchName(string $input, array $catalog): array
    {
        $tokens = $this->tokenize($input);
        if ($tokens === []) {
            return [];
        }
        $matches = [];
        foreach ($catalog as $id => $nombre) {
            $words = preg_split('/[^a-z0-9]+/', $this->asciiLower($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (array_intersect($tokens, $words) === $tokens) {
                $matches[] = (int) $id;
            }
        }

        return $matches;
    }

    /** @return array<int, string> */
    private function tokenize(string $input): array
    {
        $generic = ['region', 'comuna', 'de', 'del', 'la', 'el'];
        $tokens = preg_split('/[^a-z0-9]+/', $this->asciiLower($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $token): bool => ! in_array($token, $generic, true)));
    }

    private function asciiLower(string $value): string
    {
        return Str::ascii(mb_strtolower(trim($value)));
    }
}
