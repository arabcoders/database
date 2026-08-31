<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
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
use arabcoders\database\Schema\Operation\RebuildTableOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use arabcoders\database\Schema\Operation\SchemaOperation;
use arabcoders\database\Schema\SchemaDiff;
use RuntimeException;

/** Replays schema operations without touching a database. */
final class SchemaStateApplier
{
    public function overlay(SchemaDefinition $state, SchemaDefinition $checkpoint): SchemaDefinition
    {
        $copy = $this->cloneSchema($state);
        foreach ($checkpoint->getTables() as $table) {
            $copy->addTable($this->cloneTable($table));
        }
        return $copy;
    }

    /** @param array<int,SchemaOperation> $operations */
    public function diff(SchemaDefinition $before, array $operations): SchemaDiff
    {
        $state = $this->cloneSchema($before);
        $resolved = [];
        foreach ($operations as $operation) {
            $resolved[] = $this->apply($state, $operation);
        }

        return new SchemaDiff($before, $state, $resolved);
    }

    private function apply(SchemaDefinition $schema, SchemaOperation $op): SchemaOperation
    {
        if ($op instanceof CreateTableOperation) {
            $this->failIf($schema->hasTable($op->table->name), "Table {$op->table->name} already exists.");
            $schema->addTable($this->cloneTable($op->table));
            return $op;
        }
        if ($op instanceof DropTableOperation) {
            $table = $this->table($schema, $op->table->name);
            $schema->removeTable($table->name);
            return new DropTableOperation($this->cloneTable($table));
        }
        if ($op instanceof RenameTableOperation) {
            $table = $this->table($schema, $op->from);
            $this->failIf($schema->hasTable($op->to), "Table {$op->to} already exists.");
            $schema->removeTable($op->from);
            $copy = $this->cloneTable($table);
            $copy->name = $op->to;
            $schema->addTable($copy);
            foreach ($schema->getTables() as $referencing) {
                foreach ($referencing->getForeignKeys() as $foreignKey) {
                    if ($foreignKey->referencesTable !== $op->from) {
                        continue;
                    }
                    $referencing->removeForeignKey($foreignKey->name);
                    $referencing->addForeignKey(
                        new ForeignKeyDefinition(
                            $foreignKey->name,
                            $foreignKey->columns,
                            $op->to,
                            $foreignKey->referencesColumns,
                            $foreignKey->onDelete,
                            $foreignKey->onUpdate,
                        ),
                    );
                }
            }
            return $op;
        }
        if ($op instanceof RebuildTableOperation) {
            $from = $this->table($schema, $op->from->name);
            $schema->removeTable($from->name);
            $schema->addTable($this->cloneTable($op->to));
            return new RebuildTableOperation($this->cloneTable($from), $this->cloneTable($op->to));
        }

        $tableName = $op->getTableName();
        if (null === $tableName) {
            throw new RuntimeException('Schema operation has no table name: ' . $op::class);
        }
        $table = $this->table($schema, $tableName);

        if ($op instanceof AddColumnOperation) {
            $this->failIf($table->hasColumn($op->column->name), "Column {$op->column->name} already exists on {$tableName}.");
            $table->addColumn($op->column);
            return $op;
        }
        if ($op instanceof DropColumnOperation) {
            $column = $this->column($table, $op->column->name);
            $table->removeColumn($column->name);
            return new DropColumnOperation($tableName, $column);
        }
        if ($op instanceof AlterColumnOperation) {
            $from = $this->column($table, $op->to->name);
            $table->replaceColumn($from->name, $op->to);
            return new AlterColumnOperation($tableName, $from, $op->to);
        }
        if ($op instanceof RenameColumnOperation) {
            $column = $this->column($table, $op->from);
            $this->failIf($table->hasColumn($op->to), "Column {$op->to} already exists on {$tableName}.");
            $renamed = new ColumnDefinition(
                name: $op->to,
                type: $column->type,
                length: $column->length,
                precision: $column->precision,
                scale: $column->scale,
                unsigned: $column->unsigned,
                nullable: $column->nullable,
                autoIncrement: $column->autoIncrement,
                hasDefault: $column->hasDefault,
                default: $column->default,
                defaultIsExpression: $column->defaultIsExpression,
                charset: $column->charset,
                collation: $column->collation,
                comment: $column->comment,
                onUpdate: $column->onUpdate,
                previousName: $op->from,
                propertyName: $column->propertyName,
                typeName: $column->typeName,
                allowed: $column->allowed,
                check: $column->check,
                checkExpression: $column->checkExpression,
                generated: $column->generated,
                generatedExpression: $column->generatedExpression,
                generatedStored: $column->generatedStored,
            );
            $table->replaceColumn($op->from, $renamed);
            $this->renameColumnReferences($schema, $op->table, $op->from, $op->to);
            return $op;
        }
        if ($op instanceof AddIndexOperation) {
            $existing = $table->getIndex($op->index->name);
            if (null !== $existing) {
                if ($existing->equals($op->index)) {
                    return $op;
                }
                throw new RuntimeException("Index {$op->index->name} already exists.");
            }
            $table->addIndex($op->index);
            return $op;
        }
        if ($op instanceof DropIndexOperation) {
            $index = $table->getIndex($op->index->name);
            if (null === $index) {
                throw new RuntimeException("Index {$op->index->name} does not exist on {$tableName}.");
            }
            $table->removeIndex($index->name);
            return new DropIndexOperation($tableName, $index);
        }
        if ($op instanceof AddForeignKeyOperation) {
            $this->failIf(null !== $table->getForeignKey($op->foreignKey->name), "Foreign key {$op->foreignKey->name} already exists.");
            $table->addForeignKey($op->foreignKey);
            return $op;
        }
        if ($op instanceof DropForeignKeyOperation) {
            $fk = $table->getForeignKey($op->foreignKey->name);
            if (null === $fk) {
                throw new RuntimeException("Foreign key {$op->foreignKey->name} does not exist on {$tableName}.");
            }
            $table->removeForeignKey($fk->name);
            return new DropForeignKeyOperation($tableName, $fk);
        }
        if ($op instanceof AddPrimaryKeyOperation) {
            $this->failIf([] !== $table->getPrimaryKey(), "Primary key already exists on {$tableName}.");
            $table->setPrimaryKey($op->columns);
            return $op;
        }
        if ($op instanceof DropPrimaryKeyOperation) {
            $columns = $table->getPrimaryKey();
            $this->failIf([] === $columns, "Primary key does not exist on {$tableName}.");
            $table->setPrimaryKey([]);
            return new DropPrimaryKeyOperation($tableName, $columns);
        }

        throw new RuntimeException('Unsupported schema operation: ' . $op::class);
    }

    private function table(SchemaDefinition $schema, string $name): TableDefinition
    {
        $table = $schema->getTable($name);
        if (null === $table) {
            throw new RuntimeException("Table {$name} does not exist.");
        }
        return $table;
    }

    private function column(TableDefinition $table, string $name): ColumnDefinition
    {
        $column = $table->getColumn($name);
        if (null === $column) {
            throw new RuntimeException("Column {$name} does not exist on {$table->name}.");
        }
        return $column;
    }

    private function failIf(bool $condition, string $message): void
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }

    private function cloneSchema(SchemaDefinition $schema): SchemaDefinition
    {
        $copy = new SchemaDefinition();
        foreach ($schema->getTables() as $table) {
            $copy->addTable($this->cloneTable($table));
        }
        return $copy;
    }

    private function renameColumnReferences(SchemaDefinition $schema, string $tableName, string $from, string $to): void
    {
        foreach ($schema->getTables() as $table) {
            $primary = array_map(static fn(string $column): string => $column === $from && $table->name === $tableName
                ? $to
                : $column, $table->getPrimaryKey());
            $table->setPrimaryKey($primary);
            foreach ($table->getIndexes() as $index) {
                $columns = array_map(static fn(string $column): string => $column === $from && $table->name === $tableName
                    ? $to
                    : $column, $index->columns);
                $lengths = $index->lengths;
                if ($table->name === $tableName && array_key_exists($from, $lengths)) {
                    $lengths[$to] = $lengths[$from];
                    unset($lengths[$from]);
                }
                if ($columns !== $index->columns || $lengths !== $index->lengths) {
                    $table->removeIndex($index->name);
                    $table->addIndex(
                        new IndexDefinition(
                            $index->name,
                            $columns,
                            $index->unique,
                            $index->type,
                            $index->algorithm,
                            $lengths,
                            $index->where,
                            $index->expression,
                        ),
                    );
                }
            }
            foreach ($table->getForeignKeys() as $foreignKey) {
                $columns = $foreignKey->columns;
                $referencesColumns = $foreignKey->referencesColumns;
                if ($table->name === $tableName) {
                    $columns = array_map(static fn(string $column): string => $column === $from ? $to : $column, $columns);
                }
                if ($foreignKey->referencesTable === $tableName) {
                    $referencesColumns = array_map(static fn(string $column): string => $column === $from
                        ? $to
                        : $column, $referencesColumns);
                }
                if ($columns !== $foreignKey->columns || $referencesColumns !== $foreignKey->referencesColumns) {
                    $table->removeForeignKey($foreignKey->name);
                    $table->addForeignKey(
                        new ForeignKeyDefinition(
                            $foreignKey->name,
                            $columns,
                            $foreignKey->referencesTable,
                            $referencesColumns,
                            $foreignKey->onDelete,
                            $foreignKey->onUpdate,
                        ),
                    );
                }
            }
        }
    }

    private function cloneTable(TableDefinition $table): TableDefinition
    {
        $copy = new TableDefinition(
            $table->name,
            $table->engine,
            $table->charset,
            $table->collation,
            $table->previousName,
            $table->sourceClass,
        );
        foreach ($table->getColumns() as $v) {
            $copy->addColumn($v);
        }
        foreach ($table->getIndexes() as $v) {
            $copy->addIndex($v);
        }
        foreach ($table->getForeignKeys() as $v) {
            $copy->addForeignKey($v);
        }
        $copy->setPrimaryKey($table->getPrimaryKey());
        return $copy;
    }
}
