<?php

namespace App\Domain\Maps\Services;

/**
 * Normaliza identificadores de lotes provenientes de archivos geograficos.
 *
 * Determinista: no usa IA. Ejemplos que convergen a "6-2":
 * "6-2", "Lote 6-2", "LOTE_6_2", "lote 6 - 2", "Parcela 6-2".
 * Preserva identificadores realmente distintos ("6-21" != "6-2").
 */
class MapIdentifierNormalizer
{
    private const FILLER_WORDS = 'lote|parcela|lot|sitio|predio|manzana|nro|nro\.|n°|nº|no|num|numero|Número|piso';

    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtolower(trim($raw), 'UTF-8');
        $value = $this->removeAccents($value);

        // Separadores subrayado -> espacio para poder tratar palabras con \b
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/\b('.self::FILLER_WORDS.')\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/[#:\.]|\b(n°|nº)\s*/u', ' ', $value) ?? $value;

        // Espacios y otros separadores -> guion, colapsando repetidos
        $value = str_replace('\\', '/', $value);
        $value = preg_replace('/[\s\/]+/', '-', $value) ?? $value;
        $value = preg_replace('/-+/', '-', $value) ?? $value;

        $value = trim($value, " -\t\n\r");

        return $value === '' ? null : $value;
    }

    /**
     * Equivalencia numerica ignorando ceros a la izquierda: "07" == "7".
     */
    public function isNumericEquivalent(string $a, string $b): bool
    {
        if (! preg_match('/^\d+$/', $a) || ! preg_match('/^\d+$/', $b)) {
            return false;
        }

        return (int) $a === (int) $b;
    }

    /**
     * Similitud 0-100 para diferencias menores de escritura ("6-2b" vs "6-2b.").
     */
    public function similarity(string $a, string $b): int
    {
        if ($a === $b) {
            return 100;
        }

        $max = max(mb_strlen($a), mb_strlen($b));

        if ($max === 0) {
            return 100;
        }

        return (int) round((1 - (levenshtein($a, $b) / $max)) * 100);
    }

    private function removeAccents(string $value): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];

        return strtr($value, $map);
    }
}
