<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Migration
{
    public function __construct(
        public string $id,
        public string $name = '',
    ) {}
}
