<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

final readonly class MigrationProbeResult
{
    public function __construct(
        public string $direction,
        public bool $needed,
        public array $migrations,
        public array $lock,
        public array $issues = [],
    ) {}
}
