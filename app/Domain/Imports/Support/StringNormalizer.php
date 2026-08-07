<?php

namespace App\Domain\Imports\Support;

final class StringNormalizer
{
    /**
     * @var array<string, string>
     */
    private const ACCENTS = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ú' => 'u',
        'Ü' => 'u',
        'Ñ' => 'n',
    ];

    public static function fold(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return mb_strtolower(strtr(trim($value), self::ACCENTS));
    }

    public static function header(?string $value): string
    {
        $folded = self::fold($value);

        return (string) preg_replace('/\s+/', '_', $folded);
    }
}
