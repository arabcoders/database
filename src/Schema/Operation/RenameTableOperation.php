<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

final readonly class RenameTableOperation implements SchemaOperation
{
    public const string TYPE = 'rename_table';

    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTableName(): ?string
    {
        return $this->to;
    }
}
