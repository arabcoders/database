<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

final readonly class MigrationRequest
{
    public function __construct(
        public string $direction = 'up',
        public bool $dryRun = true,
        public int $steps = 0,
        public bool $force = false,
        public bool $repair = false,
    ) {}
}
