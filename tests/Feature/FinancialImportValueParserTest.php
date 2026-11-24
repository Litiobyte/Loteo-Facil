<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\FinancialImportValueParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialImportValueParserTest extends TestCase
{
    public function test_normalizes_chilean_and_international_amounts_without_floats(): void
    {
        $parser = app(FinancialImportValueParser::class);
        $this->assertSame('1234567.89', $parser->amount('1.234.567,89'));
        $this->assertSame('1234567.89', $parser->amount('1,234,567.89'));
        $this->assertSame('-1234567.00', $parser->amount('-1.234.567'));
        $this->assertSame('0.00', $parser->amount('-0,00'));
        $this->assertSame('1234.50', $parser->amount('1234,5'));
    }

    public function test_rejects_ambiguous_grouping_instead_of_guessing(): void
    {
        $this->expectException(ValidationException::class);
        app(FinancialImportValueParser::class)->amount('1.234');
    }

    public function test_accepts_iso_date(): void
    {
        $this->assertSame('2020-02-29', app(FinancialImportValueParser::class)->date('2020-02-29'));
    }

    public function test_rejects_nonexistent_iso_date(): void
    {
        $this->expectException(ValidationException::class);
        app(FinancialImportValueParser::class)->date('2021-02-29');
    }
}
