<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

final readonly class ForeignKeyDefinition
{
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencesTable,
        public array $referencesColumns,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
    ) {}

    /**
     * Determine whether this definition is semantically equivalent to another definition.
     * @param self $other Other.
     * @return bool
     */

    public function equals(self $other): bool
    {
        return (
            $this->columns === $other->columns
            && $this->referencesTable === $other->referencesTable
            && $this->referencesColumns === $other->referencesColumns
            && $this->onDelete === $other->onDelete
            && $this->onUpdate === $other->onUpdate
        );
    }
}
