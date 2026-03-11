<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Seeder\SeederDefinition;
use arabcoders\database\Seeder\SeederExecutionEntry;

final readonly class SeederResult
{
    public function __construct(
        public array $seeders,
        public bool $dryRun,
        public array $entries = [],
    ) {}

    /**
     * @return array<int,SeederExecutionEntry>
     */
    public function executionEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<int,SeederDefinition>
     */
    public function definitions(): array
    {
        return $this->seeders;
    }
}
