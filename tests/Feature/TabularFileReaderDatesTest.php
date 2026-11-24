<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\TabularFileReader;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class TabularFileReaderDatesTest extends TestCase
{
    public function test_xlsx_typed_date_is_converted_to_iso(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('date.xlsx');
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['date']));
        $writer->addRow(Row::fromValuesWithStyles([new DateTimeImmutable('2020-02-29 00:00:00')], null, [(new Style)->setFormat('yyyy-mm-dd')]));
        $writer->close();

        $parsed = app(TabularFileReader::class)->read($path, 'xlsx');
        $this->assertSame('2020-02-29', $parsed['rows'][2][0]);
    }

    public function test_xlsx_date_with_time_is_not_silently_truncated(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('datetime.xlsx');
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['date']));
        $writer->addRow(Row::fromValuesWithStyles([new DateTimeImmutable('2020-02-29 12:00:00')], null, [(new Style)->setFormat('yyyy-mm-dd hh:mm:ss')]));
        $writer->close();

        $this->expectException(ValidationException::class);
        app(TabularFileReader::class)->read($path, 'xlsx');
    }
}
