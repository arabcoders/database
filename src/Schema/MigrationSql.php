<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

final readonly class MigrationSql
{
    public function __construct(
        public array $up,
        public array $down,
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->up) && empty($this->down);
    }
}
