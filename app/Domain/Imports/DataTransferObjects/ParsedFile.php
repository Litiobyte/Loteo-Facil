<?php

namespace App\Domain\Imports\DataTransferObjects;

final class ParsedFile
{
    /**
     * @param  list<string>  $headers
     * @param  list<array{row_number: int, data: array<string, string|int|float|null>}>  $rows
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    /**
     * @return list<string>
     */
    public function missingHeaders(array $required): array
    {
        return array_values(array_diff($required, $this->headers));
    }
}
