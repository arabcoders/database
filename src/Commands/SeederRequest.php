<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederTransactionMode;

final readonly class SeederRequest
{
    public function __construct(
        public string $classFilter = '',
        public bool $dryRun = true,
        public string $mode = SeederRunMode::AUTO,
        public string $transactionMode = SeederTransactionMode::PER_SEEDER,
        public ?string $tag = null,
        public ?string $group = null,
    ) {}
}
