<?php

namespace App\Domain\Imports\Contracts;

use App\Domain\Imports\DataTransferObjects\ParsedFile;

interface FileParserInterface
{
    public function parse(string $path): ParsedFile;
}
