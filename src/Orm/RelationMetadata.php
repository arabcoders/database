<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

final readonly class RelationMetadata
{
    public const string TYPE_BELONGS_TO = 'belongs_to';
    public const string TYPE_HAS_ONE = 'has_one';
    public const string TYPE_HAS_MANY = 'has_many';
    public const string TYPE_BELONGS_TO_MANY = 'belongs_to_many';

    public function __construct(
        public string $name,
        public string $type,
        public string $target,
        public string $foreignKey,
        public string $localKey,
        public ?string $pivotTable = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $relatedKey = null,
        public array $pivotColumns = [],
        public string $pivotProperty = 'pivot',
    ) {}

    public function isToMany(): bool
    {
        return in_array($this->type, [self::TYPE_HAS_MANY, self::TYPE_BELONGS_TO_MANY], true);
    }

    public function isPivot(): bool
    {
        return self::TYPE_BELONGS_TO_MANY === $this->type;
    }
}
