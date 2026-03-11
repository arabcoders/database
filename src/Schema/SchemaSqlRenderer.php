<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use arabcoders\database\Schema\Dialect\SqliteDialect;
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

final class SchemaSqlRenderer
{
    public function __construct(
        private SchemaDialectInterface $dialect,
    ) {}

    /**
     * Render a schema diff into executable up/down SQL statement lists.
     *
     * @param SchemaDiff $diff Schema diff to render.
     * @return MigrationSql
     */
    public function render(SchemaDiff $diff): MigrationSql
    {
        $operations = $diff->getOperations();
        $operations = $this->splitCreateTableForeignKeys($operations);

        if ($this->dialect instanceof SqliteDialect) {
            $operations = $this->applySqliteRebuild($diff, $operations);
        }

        $operations = $this->orderOperations($operations);

        $up = [];
        foreach ($operations as $operation) {
            $sql = $this->sqlForOperation($operation, 'up');
            $up = array_merge($up, $this->flattenSqlArray($sql));
        }

        $down = [];
        foreach (array_reverse($operations) as $operation) {
            $sql = $this->sqlForOperation($operation, 'down');
            $down = array_merge($down, $this->flattenSqlArray($sql));
        }

        $up = array_values(array_filter($up, static fn(string $sql) => '' !== trim($sql)));
        $down = array_values(array_filter($down, static fn(string $sql) => '' !== trim($sql)));

        return new MigrationSql($up, $down);
    }

    /**
     * @param array<mixed> $sql
     * @return array<int,string>
     */
    private function flattenSqlArray(array $sql): array
    {
        $result = [];
        foreach ($sql as $item) {
            if (is_string($item)) {
                $result[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            foreach ($item as $subItem) {
                if (!is_string($subItem)) {
                    continue;
                }
                $result[] = $subItem;
            }
        }

        return $result;
    }

    /**
     * @param array<int,SchemaOperation> $operations
     * @return array<int,SchemaOperation>
     */
    private function orderOperations(array $operations): array
    {
        $order = [
            RenameTableOperation::class => 5,
            DropForeignKeyOperation::class => 10,
            DropIndexOperation::class => 20,
            DropPrimaryKeyOperation::class => 30,
            RenameColumnOperation::class => 35,
            DropColumnOperation::class => 40,
            DropTableOperation::class => 50,
            CreateTableOperation::class => 60,
            AddColumnOperation::class => 70,
            AlterColumnOperation::class => 80,
            RebuildTableOperation::class => 85,
            AddPrimaryKeyOperation::class => 90,
            AddIndexOperation::class => 100,
            AddForeignKeyOperation::class => 110,
        ];

        usort($operations, static function (SchemaOperation $a, SchemaOperation $b) use ($order) {
            $weightA = $order[$a::class] ?? 1000;
            $weightB = $order[$b::class] ?? 1000;

            return $weightA <=> $weightB;
        });

        return $operations;
    }

    /**
     * @param array<int,SchemaOperation> $operations
     * @return array<int,SchemaOperation>
     */
    private function splitCreateTableForeignKeys(array $operations): array
    {
        if (!$this->dialect->supportsForeignKeys()) {
            return $operations;
        }

        $updated = [];
        foreach ($operations as $operation) {
            if (!$operation instanceof CreateTableOperation) {
                $updated[] = $operation;
                continue;
            }

            $foreignKeys = $operation->table->getForeignKeys();
            if (empty($foreignKeys)) {
                $updated[] = $operation;
                continue;
            }

            $table = $this->cloneTableWithoutForeignKeys($operation->table);
            $updated[] = new CreateTableOperation($table);

            foreach ($foreignKeys as $foreignKey) {
                $updated[] = new AddForeignKeyOperation($table->name, $foreignKey);
            }
        }

        return $updated;
    }

    private function cloneTableWithoutForeignKeys(TableDefinition $table): TableDefinition
    {
        $copy = new TableDefinition(
            name: $table->name,
            engine: $table->engine,
            charset: $table->charset,
            collation: $table->collation,
            previousName: $table->previousName,
            sourceClass: $table->sourceClass,
        );

        foreach ($table->getColumns() as $column) {
            $copy->addColumn($column);
        }

        foreach ($table->getIndexes() as $index) {
            $copy->addIndex($index);
        }

        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
            $copy->setPrimaryKey($primaryKey);
        }

        return $copy;
    }

    /**
     * @param array<int,SchemaOperation> $operations
     * @return array<int,SchemaOperation>
     */
    private function applySqliteRebuild(SchemaDiff $diff, array $operations): array
    {
        $byTable = [];
        foreach ($operations as $operation) {
            $table = $operation->getTableName();
            if (null === $table) {
                continue;
            }
            $byTable[$table][] = $operation;
        }

        $rebuildTables = [];
        foreach ($byTable as $table => $tableOps) {
            if (!$this->requiresSqliteRebuild($tableOps)) {
                continue;
            }

            $rebuildTables[$table] = true;
        }

        if (empty($rebuildTables)) {
            return $operations;
        }

        $filtered = [];
        foreach ($operations as $operation) {
            $table = $operation->getTableName();
            if (null !== $table && isset($rebuildTables[$table])) {
                continue;
            }
            $filtered[] = $operation;
        }

        foreach (array_keys($rebuildTables) as $table) {
            $toTable = $diff->to->getTable($table);
            if (null === $toTable) {
                continue;
            }

            $fromTable = $diff->from->getTable($table);
            if (null === $fromTable && null !== $toTable->previousName) {
                $fromTable = $diff->from->getTable($toTable->previousName);
            }

            if (null === $fromTable) {
                continue;
            }

            $filtered[] = new RebuildTableOperation($fromTable, $toTable);
        }

        return $filtered;
    }

    /**
     * @param array<int,SchemaOperation> $operations
     */
    private function requiresSqliteRebuild(array $operations): bool
    {
        foreach ($operations as $operation) {
            if ($operation instanceof CreateTableOperation || $operation instanceof DropTableOperation) {
                continue;
            }

            if ($operation instanceof AddIndexOperation || $operation instanceof DropIndexOperation) {
                continue;
            }

            if ($operation instanceof RenameTableOperation) {
                continue;
            }

            if ($operation instanceof RenameColumnOperation) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function sqlForOperation(SchemaOperation $operation, string $direction): array
    {
        if ($operation instanceof CreateTableOperation) {
            return (
                'up' === $direction
                    ? [$this->dialect->createTableSql($operation->table)]
                    : [$this->dialect->dropTableSql($operation->table->name)]
            );
        }

        if ($operation instanceof DropTableOperation) {
            if ('up' === $direction) {
                return [$this->dialect->dropTableSql($operation->table->name)];
            }

            $sql = [$this->dialect->createTableSql($operation->table)];
            foreach ($operation->table->getIndexes() as $index) {
                $sql[] = $this->dialect->addIndexSql($operation->table->name, $index);
            }

            return $sql;
        }

        if ($operation instanceof AddColumnOperation) {
            if ('up' === $direction) {
                return [$this->dialect->addColumnSql($operation->table, $operation->column)];
            }

            if (!$this->dialect->supportsDropColumn()) {
                return [];
            }

            return [$this->dialect->dropColumnSql($operation->table, $operation->column->name)];
        }

        if ($operation instanceof DropColumnOperation) {
            if ('up' === $direction) {
                if (!$this->dialect->supportsDropColumn()) {
                    return [];
                }

                return [$this->dialect->dropColumnSql($operation->table, $operation->column->name)];
            }

            return [$this->dialect->addColumnSql($operation->table, $operation->column)];
        }

        if ($operation instanceof AlterColumnOperation) {
            if (!$this->dialect->supportsAlterColumn()) {
                return [];
            }

            if ('up' === $direction) {
                return [$this->dialect->alterColumnSql($operation->table, $operation->to)];
            }

            return [$this->dialect->alterColumnSql($operation->table, $operation->from)];
        }

        if ($operation instanceof AddIndexOperation) {
            $upSql = $this->dialect->addIndexSql($operation->table, $operation->index);
            if ('up' === $direction) {
                return is_array($upSql) ? $upSql : [$upSql];
            }

            return [$this->dialect->dropIndexSql($operation->table, $operation->index)];
        }

        if ($operation instanceof DropIndexOperation) {
            if ('up' === $direction) {
                $sql = $this->dialect->dropIndexSql($operation->table, $operation->index);
                return is_array($sql) ? $sql : [$sql];
            }

            $sql = $this->dialect->addIndexSql($operation->table, $operation->index);
            return is_array($sql) ? $sql : [$sql];
        }

        if ($operation instanceof AddForeignKeyOperation) {
            if (!$this->dialect->supportsForeignKeys()) {
                return [];
            }

            if ('up' === $direction) {
                return [$this->dialect->addForeignKeySql($operation->table, $operation->foreignKey)];
            }

            return [$this->dialect->dropForeignKeySql($operation->table, $operation->foreignKey)];
        }

        if ($operation instanceof DropForeignKeyOperation) {
            if (!$this->dialect->supportsForeignKeys()) {
                return [];
            }

            if ('up' === $direction) {
                return [$this->dialect->dropForeignKeySql($operation->table, $operation->foreignKey)];
            }

            return [$this->dialect->addForeignKeySql($operation->table, $operation->foreignKey)];
        }

        if ($operation instanceof RenameTableOperation) {
            return (
                'up' === $direction
                    ? [$this->dialect->renameTableSql($operation->from, $operation->to)]
                    : [$this->dialect->renameTableSql($operation->to, $operation->from)]
            );
        }

        if ($operation instanceof RenameColumnOperation) {
            return (
                'up' === $direction
                    ? [$this->dialect->renameColumnSql($operation->table, $operation->from, $operation->to)]
                    : [$this->dialect->renameColumnSql($operation->table, $operation->to, $operation->from)]
            );
        }

        if ($operation instanceof AddPrimaryKeyOperation) {
            if (!$this->dialect->supportsPrimaryKeyAlter()) {
                return [];
            }

            if ('up' === $direction) {
                return [$this->dialect->addPrimaryKeySql($operation->table, $operation->columns)];
            }

            return [$this->dialect->dropPrimaryKeySql($operation->table)];
        }

        if ($operation instanceof DropPrimaryKeyOperation) {
            if (!$this->dialect->supportsPrimaryKeyAlter()) {
                return [];
            }

            if ('up' === $direction) {
                return [$this->dialect->dropPrimaryKeySql($operation->table)];
            }

            return [$this->dialect->addPrimaryKeySql($operation->table, $operation->columns)];
        }

        if ($operation instanceof RebuildTableOperation && $this->dialect instanceof SqliteDialect) {
            return 'up' === $direction
                ? $this->dialect->rebuildTableSql($operation->from, $operation->to)
                : $this->dialect->rebuildTableSql($operation->to, $operation->from);
        }

        return [];
    }
}
