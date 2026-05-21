<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

final readonly class MigrationSqlStep
{
    /**
     * @param array<int,string> $up
     * @param array<int,string> $down
     */
    public function __construct(
        public string $type,
        public array $up,
        public array $down,
        public bool $reversible,
    ) {}
}
