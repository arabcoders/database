<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

final class SchemaDefinition
{
    /**
     * @var array<string,TableDefinition>
     */
    private array $tables = [];

    public function addTable(TableDefinition $table): void
    {
        $this->tables[$table->name] = $table;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    public function getTable(string $name): ?TableDefinition
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * @return array<string,TableDefinition>
     */
    public function getTables(): array
    {
        return $this->tables;
    }
}
