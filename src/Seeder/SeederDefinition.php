<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

final readonly class SeederDefinition
{
    public function __construct(
        public string $name,
        public string $class,
        public array $dependsOn = [],
        public array $tags = [],
        public array $groups = [],
        public string $mode = SeederRunMode::ALWAYS,
    ) {}
}
