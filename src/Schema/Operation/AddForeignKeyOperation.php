<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

use arabcoders\database\Schema\Definition\ForeignKeyDefinition;

final readonly class AddForeignKeyOperation implements SchemaOperation
{
    public const string TYPE = 'add_foreign_key';

    public function __construct(
        public string $table,
        public ForeignKeyDefinition $foreignKey,
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
