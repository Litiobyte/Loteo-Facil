<?php

namespace App\Support;

class ChileanNumber
{
    public static function format(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $parts)) {
            return $value;
        }

        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[2]);
        $fraction = rtrim($parts[3] ?? '', '0');

        return $parts[1].$integer.($fraction !== '' ? ','.$fraction : '');
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (! preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d+)?$/D', $value)) {
            return $value;
        }

        return str_replace(['.', ','], ['', '.'], $value);
    }
}
