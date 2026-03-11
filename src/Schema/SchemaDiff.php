<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Operation\SchemaOperation;

final readonly class SchemaDiff
{
    public function __construct(
        public SchemaDefinition $from,
        public SchemaDefinition $to,
        public array $operations = [],
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->operations);
    }

    /**
     * @return array<int,SchemaOperation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }
}
