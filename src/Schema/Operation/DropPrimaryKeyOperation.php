<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

final readonly class DropPrimaryKeyOperation implements SchemaOperation
{
    public const string TYPE = 'drop_primary_key';

    public function __construct(
        public string $table,
        public array $columns,
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
