<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

use arabcoders\database\Schema\Definition\IndexDefinition;

final readonly class AddIndexOperation implements SchemaOperation
{
    public const string TYPE = 'add_index';

    public function __construct(
        public string $table,
        public IndexDefinition $index,
    ) {}

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTableName(): ?string
    {
        return $this->table;
    }
}
