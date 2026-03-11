<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\AddForeignKeyOperation;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\AddPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\AlterColumnOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropColumnOperation;
use arabcoders\database\Schema\Operation\DropForeignKeyOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\DropPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\DropTableOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use arabcoders\database\Schema\Operation\SchemaOperation;

final class SchemaDiffer
{
    /**
     * Compare two schema definitions and produce an ordered list of migration operations.
     *
     * @param SchemaDefinition $from Current database schema.
     * @param SchemaDefinition $to Target schema generated from models.
     * @return SchemaDiff
     */
    public function diff(SchemaDefinition $from, SchemaDefinition $to): SchemaDiff
    {
        $operations = [];
        $pairedFromTables = [];

        foreach ($to->getTables() as $tableName => $table) {
            $fromTable = $from->getTable($tableName);
            if (null === $fromTable && null !== $table->previousName && $from->hasTable($table->previousName)) {
                $fromTable = $from->getTable($table->previousName);
                $operations[] = new RenameTableOperation($table->previousName, $tableName);
            }

            if (null === $fromTable) {
                $operations[] = new CreateTableOperation($table);

                foreach ($table->getIndexes() as $index) {
                    $operations[] = new AddIndexOperation($tableName, $index);
                }

                continue;
            }

            $pairedFromTables[$fromTable->name] = true;

            array_push($operations, ...$this->diffTable($fromTable, $table));
        }

        foreach ($from->getTables() as $tableName => $table) {
            if (isset($pairedFromTables[$tableName]) || $to->hasTable($tableName)) {
                continue;
            }

            $operations[] = new DropTableOperation($table);
        }

        return new SchemaDiff($from, $to, $operations);
    }

    /**
     * @return array<int,SchemaOperation>
     */
    private function diffTable(TableDefinition $from, TableDefinition $to): array
    {
        $operations = [];
        $matchedFromColumns = [];

        foreach ($to->getColumns() as $name => $column) {
            $fromColumn = $from->getColumn($name);
            if (null === $fromColumn) {
                $renameFrom = $this->resolveRenameColumn($from, $column, $matchedFromColumns);
                if (null !== $renameFrom) {
                    $operations[] = new RenameColumnOperation($to->name, $renameFrom->name, $column->name);
                    $matchedFromColumns[$renameFrom->name] = true;

                    if (!$column->equals($renameFrom)) {
                        $operations[] = new AlterColumnOperation($to->name, $renameFrom, $column);
                    }

                    continue;
                }

                $operations[] = new AddColumnOperation($to->name, $column);
                continue;
            }

            if (!$column->equals($fromColumn)) {
                $operations[] = new AlterColumnOperation($to->name, $fromColumn, $column);
            }

            $matchedFromColumns[$fromColumn->name] = true;
        }

        foreach ($from->getColumns() as $name => $column) {
            if (isset($matchedFromColumns[$name]) || $to->hasColumn($name)) {
                continue;
            }

            $operations[] = new DropColumnOperation($to->name, $column);
        }

        if ($from->getPrimaryKey() !== $to->getPrimaryKey()) {
            if (!empty($from->getPrimaryKey())) {
                $operations[] = new DropPrimaryKeyOperation($from->name, $from->getPrimaryKey());
            }
            if (!empty($to->getPrimaryKey())) {
                $operations[] = new AddPrimaryKeyOperation($to->name, $to->getPrimaryKey());
            }
        }

        foreach ($to->getIndexes() as $name => $index) {
            $fromIndex = $from->getIndex($name);
            if (null === $fromIndex) {
                $operations[] = new AddIndexOperation($to->name, $index);
                continue;
            }

            if (!$index->equals($fromIndex)) {
                $operations[] = new DropIndexOperation($to->name, $fromIndex);
                $operations[] = new AddIndexOperation($to->name, $index);
            }
        }

        foreach ($from->getIndexes() as $name => $index) {
            if (null !== $to->getIndex($name)) {
                continue;
            }

            $operations[] = new DropIndexOperation($to->name, $index);
        }

        foreach ($to->getForeignKeys() as $name => $foreignKey) {
            $fromForeignKey = $from->getForeignKey($name);
            if (null === $fromForeignKey) {
                $operations[] = new AddForeignKeyOperation($to->name, $foreignKey);
                continue;
            }

            if (!$foreignKey->equals($fromForeignKey)) {
                $operations[] = new DropForeignKeyOperation($to->name, $fromForeignKey);
                $operations[] = new AddForeignKeyOperation($to->name, $foreignKey);
            }
        }

        foreach ($from->getForeignKeys() as $name => $foreignKey) {
            if (null !== $to->getForeignKey($name)) {
                continue;
            }

            $operations[] = new DropForeignKeyOperation($to->name, $foreignKey);
        }

        return $operations;
    }

    /**
     * @param array<string,bool> $matchedFromColumns
     */
    private function resolveRenameColumn(TableDefinition $from, ColumnDefinition $column, array $matchedFromColumns): ?ColumnDefinition
    {
        if (null === $column->previousName || $column->previousName === $column->name) {
            return null;
        }

        $candidate = $from->getColumn($column->previousName);
        if (null === $candidate || isset($matchedFromColumns[$candidate->name])) {
            return null;
        }

        return $candidate;
    }
}
