<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

final class TableDefinition
{
    /**
     * @var array<string,ColumnDefinition>
     */
    private array $columns = [];

    /**
     * @var array<string,IndexDefinition>
     */
    private array $indexes = [];

    /**
     * @var array<string,ForeignKeyDefinition>
     */
    private array $foreignKeys = [];

    /**
     * @var array<int,string>
     */
    private array $primaryKey = [];

    public function __construct(
        public string $name,
        public array $engine = [],
        public array $charset = [],
        public array $collation = [],
        public ?string $previousName = null,
        public ?string $sourceClass = null,
    ) {}

    public function addColumn(ColumnDefinition $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function removeColumn(string $name): void
    {
        unset($this->columns[$name]);
    }

    public function replaceColumn(string $oldName, ColumnDefinition $column): void
    {
        if (!array_key_exists($oldName, $this->columns)) {
            throw new \RuntimeException("Column {$oldName} does not exist on {$this->name}.");
        }

        $columns = [];
        foreach ($this->columns as $name => $existing) {
            $columns[$name === $oldName ? $column->name : $name] = $name === $oldName ? $column : $existing;
        }
        $this->columns = $columns;
    }

    public function getColumn(string $name): ?ColumnDefinition
    {
        return $this->columns[$name] ?? null;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /**
     * @return array<string,ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function addIndex(IndexDefinition $index): void
    {
        $this->indexes[$index->name] = $index;
    }

    public function removeIndex(string $name): void
    {
        unset($this->indexes[$name]);
    }

    public function getIndex(string $name): ?IndexDefinition
    {
        return $this->indexes[$name] ?? null;
    }

    /**
     * @return array<string,IndexDefinition>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    public function addForeignKey(ForeignKeyDefinition $foreignKey): void
    {
        $this->foreignKeys[$foreignKey->name] = $foreignKey;
    }

    public function removeForeignKey(string $name): void
    {
        unset($this->foreignKeys[$name]);
    }

    public function getForeignKey(string $name): ?ForeignKeyDefinition
    {
        return $this->foreignKeys[$name] ?? null;
    }

    /**
     * @return array<string,ForeignKeyDefinition>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * @param array<int,string> $columns
     */
    public function setPrimaryKey(array $columns): void
    {
        $this->primaryKey = $columns;
    }

    /**
     * @return array<int,string>
     */
    public function getPrimaryKey(): array
    {
        return $this->primaryKey;
    }
}
