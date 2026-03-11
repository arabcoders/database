<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes;

use arabcoders\database\Seeder\SeederRunMode;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Seeder
{
    public function __construct(
        public string $name,
        public array $dependsOn = [],
        public array $tags = [],
        public array $groups = [],
        public string $mode = SeederRunMode::ALWAYS,
    ) {}
}
