<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Orm;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class BelongsToMany
{
    public function __construct(
        public string $target,
        public string $pivotTable,
        public string $foreignPivotKey,
        public string $relatedPivotKey,
        public string $parentKey = 'id',
        public string $relatedKey = 'id',
        public array $pivotColumns = [],
        public string $pivotProperty = 'pivot',
    ) {}
}
