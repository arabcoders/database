<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Orm;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasOne
{
    public function __construct(
        public string $target,
        public string $foreignKey,
        public string $localKey = 'id',
    ) {}
}
