<?php

namespace Tests\Feature\Imports\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait CreatesTempImportFiles
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    protected function tempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import_').'.csv';

        file_put_contents($path, $content);

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function tempXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import_').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->tempFiles[] = $path;

        return $path;
    }
}
