<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

use arabcoders\database\Schema\Definition\TableDefinition;

final readonly class RebuildTableOperation implements SchemaOperation
{
    public const string TYPE = 'rebuild_table';

    public function __construct(
        public TableDefinition $from,
        public TableDefinition $to,
    ) {}

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTableName(): ?string
    {
        return $this->to->name;
    }
}
