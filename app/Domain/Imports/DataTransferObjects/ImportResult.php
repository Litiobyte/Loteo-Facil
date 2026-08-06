<?php

namespace App\Domain\Imports\DataTransferObjects;

final class ImportResult
{
    /**
     * @param  list<array{row: int, field: ?string, message: string}>  $errors
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public array $errors = [],
    ) {}

    public function successful(): int
    {
        return $this->created + $this->updated;
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
