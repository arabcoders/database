<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

final readonly class MigrationDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public string $class,
    ) {}
}
