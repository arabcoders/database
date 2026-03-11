<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Validator\ValidationType;

final readonly class EntityMetadata
{
    public function __construct(
        public string $className,
        public string $table,
        public array $columnsByProperty,
        public array $propertiesByColumn,
        public array $primaryKeys,
        public array $autoIncrementKeys,
        public array $relations,
        public array $transformsByProperty,
        public array $validatorsByProperty,
        public array $hooksByProperty,
        public ?string $softDeleteColumn,
    ) {}

    public function columnFor(string $property): ?string
    {
        return $this->columnsByProperty[$property] ?? null;
    }

    public function propertyFor(string $column): ?string
    {
        return $this->propertiesByColumn[$column] ?? null;
    }

    public function relationFor(string $property): ?RelationMetadata
    {
        return $this->relations[$property] ?? null;
    }

    public function transformFor(string $property): ?callable
    {
        return $this->transformsByProperty[$property] ?? null;
    }

    public function hooksFor(string $property): array
    {
        return $this->hooksByProperty[$property] ?? [];
    }

    /**
     * @return array<int,array{types:array<int,ValidationType>,callable:callable}>
     */
    public function validatorsFor(string $property): array
    {
        return $this->validatorsByProperty[$property] ?? [];
    }

    public function isSoftDelete(): bool
    {
        return null !== $this->softDeleteColumn && '' !== $this->softDeleteColumn;
    }
}
