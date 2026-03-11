<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

use arabcoders\database\Schema\Definition\ColumnDefinition;

final readonly class AlterColumnOperation implements SchemaOperation
{
    public const string TYPE = 'alter_column';

    public function __construct(
        public string $table,
        public ColumnDefinition $from,
        public ColumnDefinition $to,
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
