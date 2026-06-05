<?php

namespace App\Support;

class ChileanRut
{
    public static function normalize(?string $rut): ?string
    {
        if ($rut === null) {
            return null;
        }

        $clean = strtoupper(trim($rut));
        $clean = str_replace(['.', ' '], '', $clean);
        $clean = preg_replace('/[^0-9K-]/', '', $clean) ?? '';

        if ($clean === '') {
            return null;
        }

        if (! str_contains($clean, '-')) {
            $dv = substr($clean, -1);
            $number = substr($clean, 0, -1);

            if ($number === '' || $dv === '') {
                return null;
            }

            return $number.'-'.$dv;
        }

        [$number, $dv] = explode('-', $clean, 2);

        if ($number === '' || $dv === '') {
            return null;
        }

        return $number.'-'.$dv;
    }

    public static function isValid(?string $rut): bool
    {
        $normalized = self::normalize($rut);

        if (! $normalized || ! preg_match('/^[0-9]+-[0-9K]$/', $normalized)) {
            return false;
        }

        [$number, $dv] = explode('-', $normalized);

        if ((int) $number <= 0) {
            return false;
        }

        $sum = 0;
        $factor = 2;

        foreach (array_reverse(str_split($number)) as $digit) {
            $sum += ((int) $digit) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $rest = 11 - ($sum % 11);

        $expected = match ($rest) {
            11 => '0',
            10 => 'K',
            default => (string) $rest,
        };

        return $expected === strtoupper($dv);
    }
}
