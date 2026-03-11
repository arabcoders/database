<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

final readonly class RenameColumnOperation implements SchemaOperation
{
    public const string TYPE = 'rename_column';

    public function __construct(
        public string $table,
        public string $from,
        public string $to,
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
