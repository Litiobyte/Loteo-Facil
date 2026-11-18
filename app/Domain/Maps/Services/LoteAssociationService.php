<?php

namespace App\Domain\Maps\Services;

use App\Domain\Maps\Enums\AssociationMatch;
use App\Models\Lote;
use Illuminate\Support\Collection;

/**
 * Propone asociaciones poligono -> lote por identificador normalizado.
 * Determinista, limitada a los lotes de la etapa (proyecto) correspondiente.
 * Nunca asigna automaticamente coincidencias probables o ambiguas.
 */
class LoteAssociationService
{
    public function __construct(private MapIdentifierNormalizer $normalizer) {}

    /**
     * @param  array<int, array{identifier?: ?string, name?: ?string, area_m2?: ?float, duplicate?: bool}>  $items
     * @return array<int, array{match: AssociationMatch, lote_id: ?int, confidence: ?int, warnings: array<int, string>}>
     */
    public function propose(array $items, int $etapaId): array
    {
        $candidates = $this->buildCandidateIndex($etapaId);
        $results = [];
        $seenExact = [];

        foreach ($items as $item) {
            $identifier = $this->normalizer->normalize($item['identifier'] ?? null)
                ?? $this->normalizer->normalize($item['name'] ?? null);

            $warnings = [];

            if ($identifier !== null && ($item['duplicate'] ?? false)) {
                $warnings[] = 'Existen varias geometrías con el mismo identificador en el archivo.';
            }

            $proposal = $this->matchIdentifier($identifier, $candidates);

            if ($proposal['match'] !== AssociationMatch::None
                && isset($item['area_m2'])
                && $item['area_m2'] !== null
                && ($proposal['lote_area_m2'] ?? null) !== null
                && $proposal['lote_area_m2'] > 0
            ) {
                $diff = abs((float) $item['area_m2'] - (float) $proposal['lote_area_m2']) / (float) $proposal['lote_area_m2'];

                if ($diff > 0.20) {
                    $warnings[] = 'La superficie geográfica difiere más de 20% de la superficie registrada del lote.';
                }
            }

            if ($proposal['match'] === AssociationMatch::Exact) {
                $seenExact[] = $proposal['lote_id'];
            }

            $results[] = $proposal + ['warnings' => $warnings];
        }

        // Marcar duplicados: un mismo lote propuesto en varias coincidencias exactas
        $counts = array_count_values(array_filter($seenExact));
        $counts = array_filter($counts, fn (int $c): bool => $c > 1);

        foreach ($results as $index => $result) {
            if ($result['match'] === AssociationMatch::Exact
                && in_array($result['lote_id'], array_keys($counts), true)
            ) {
                $results[$index]['warnings'][] = 'Otro polígono del archivo también propone este lote.';
            }
        }

        return $results;
    }

    /**
     * @return Collection<string, array<int, array{id: int, codigo: string, lote_area_m2: ?float}>>
     */
    private function buildCandidateIndex(int $etapaId): Collection
    {
        return Lote::query()
            ->where('etapa_id', $etapaId)
            ->orderBy('id')
            ->get(['id', 'codigo', 'metros_cuadrados'])
            ->groupBy(function (Lote $lote): string {
                return $this->normalizer->normalize($lote->codigo) ?? '__'.(string) $lote->id;
            });
    }

    /**
     * @param  Collection<string, array<int, array{id: int, codigo: string, lote_area_m2: ?float}>>  $candidates
     * @return array{match: AssociationMatch, lote_id: ?int, confidence: ?int}
     */
    private function matchIdentifier(?string $identifier, Collection $candidates): array
    {
        if ($identifier === null) {
            return ['match' => AssociationMatch::None, 'lote_id' => null, 'confidence' => null];
        }

        // Coincidencia por identificador normalizado identico
        if ($candidates->has($identifier)) {
            $group = $candidates->get($identifier);

            if (count($group) === 1) {
                return [
                    'match' => AssociationMatch::Exact,
                    'lote_id' => $group[0]['id'],
                    'lote_area_m2' => $group[0]['metros_cuadrados'],
                    'confidence' => 100,
                ];
            }

            return ['match' => AssociationMatch::Ambiguous, 'lote_id' => null, 'lote_area_m2' => null, 'confidence' => null];
        }

        // Coincidencias probables: similitud alta o equivalencia numerica
        $scored = [];

        foreach ($candidates as $normalized => $group) {
            if (str_starts_with($normalized, '__')) {
                continue;
            }

            $first = $group[0];
            $similarity = $this->normalizer->similarity($identifier, $normalized);
            $numeric = $this->normalizer->isNumericEquivalent($identifier, $normalized);

            if ($similarity >= 85 || $numeric) {
                $scored[] = [
                    'lote_id' => $first['id'],
                    'lote_area_m2' => $first['lote_area_m2'],
                    'confidence' => $numeric ? max(85, $similarity) : $similarity,
                ];
            }
        }

        if (count($scored) === 1) {
            return [
                'match' => AssociationMatch::Probable,
                'lote_id' => $scored[0]['lote_id'],
                'lote_area_m2' => $scored[0]['lote_area_m2'],
                'confidence' => (int) $scored[0]['confidence'],
            ];
        }

        if (count($scored) > 1) {
            return ['match' => AssociationMatch::Ambiguous, 'lote_id' => null, 'lote_area_m2' => null, 'confidence' => null];
        }

        return ['match' => AssociationMatch::None, 'lote_id' => null, 'lote_area_m2' => null, 'confidence' => null];
    }
}
