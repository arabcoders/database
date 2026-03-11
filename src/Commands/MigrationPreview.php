<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

final readonly class MigrationPreview
{
    public function __construct(
        public array $up,
        public array $down,
    ) {}
}
