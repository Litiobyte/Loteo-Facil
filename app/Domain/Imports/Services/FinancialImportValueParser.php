<?php

namespace App\Domain\Imports\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

class FinancialImportValueParser
{
    public function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['date' => 'Use una fecha válida en formato AAAA-MM-DD.']);
        }

        return $value;
    }

    public function amount(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^(-?)([0-9.,]+)$/D', $value, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Monto inválido.']);
        }
        $number = $matches[2];
        $integer = '';
        $decimal = '';

        if (str_contains($number, '.') && str_contains($number, ',')) {
            $decimalSeparator = strrpos($number, '.') > strrpos($number, ',') ? '.' : ',';
            $groupSeparator = $decimalSeparator === '.' ? ',' : '.';
            $pattern = '/^[0-9]{1,3}(?:'.preg_quote($groupSeparator, '/').'[0-9]{3})+'.preg_quote($decimalSeparator, '/').'[0-9]{1,2}$/D';
            if (! preg_match($pattern, $number)) {
                throw ValidationException::withMessages(['amount' => 'Agrupación o decimales inválidos.']);
            }
            [$integer, $decimal] = explode($decimalSeparator, $number);
            $integer = str_replace($groupSeparator, '', $integer);
        } elseif (str_contains($number, '.') || str_contains($number, ',')) {
            $separator = str_contains($number, '.') ? '.' : ',';
            $parts = explode($separator, $number);
            if (count($parts) === 2 && preg_match('/^[0-9]+$/D', $parts[0]) && preg_match('/^[0-9]{1,2}$/D', $parts[1])) {
                [$integer, $decimal] = $parts;
            } elseif (count($parts) > 2 && preg_match('/^[0-9]{1,3}(?:'.preg_quote($separator, '/').'[0-9]{3}){2,}$/D', $number)) {
                $integer = implode('', $parts);
            } else {
                throw ValidationException::withMessages(['amount' => 'Monto ambiguo o inválido; indique dos decimales o use agrupación completa.']);
            }
        } elseif (ctype_digit($number)) {
            $integer = $number;
        } else {
            throw ValidationException::withMessages(['amount' => 'Monto inválido.']);
        }

        $integer = ltrim($integer, '0') ?: '0';
        if (strlen($integer) > 12) {
            throw ValidationException::withMessages(['amount' => 'El monto excede el límite admitido.']);
        }
        $decimal = str_pad($decimal, 2, '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || $decimal !== '00') ? '-' : '';

        return $sign.$integer.'.'.$decimal;
    }
}
