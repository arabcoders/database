<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

final readonly class MigrationOperationResult
{
    public function __construct(
        public array $migrations,
        public bool $dryRun,
    ) {}
}
