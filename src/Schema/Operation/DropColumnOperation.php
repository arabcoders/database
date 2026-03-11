<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

use arabcoders\database\Schema\Definition\ColumnDefinition;

final readonly class DropColumnOperation implements SchemaOperation
{
    public const string TYPE = 'drop_column';

    public function __construct(
        public string $table,
        public ColumnDefinition $column,
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
