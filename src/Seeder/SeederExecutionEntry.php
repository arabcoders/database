<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

final readonly class SeederExecutionEntry
{
    public function __construct(
        public SeederDefinition $definition,
        public string $status,
        public string $reason = '',
        public ?int $historyId = null,
    ) {}
}
