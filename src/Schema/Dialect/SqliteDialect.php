<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use arabcoders\database\Dialect\SqliteDialect as DatabaseSqliteDialect;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Utils\NameHelper;
use RuntimeException;

final class SqliteDialect extends AbstractSchemaDialect
{
    public function __construct(
        DatabaseDialectInterface $dialect = new DatabaseSqliteDialect(),
    ) {
        parent::__construct($dialect);
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function defaultTableEngine(): ?string
    {
        return null;
    }

    public function defaultTableCharset(): ?string
    {
        return null;
    }

    public function defaultTableCollation(): ?string
    {
        return null;
    }

    public function defaultIndexAlgorithm(IndexDefinition $index): ?string
    {
        return null;
    }

    /**
     * Normalize a logical column type for the current SQL dialect.
     * @param ColumnType $type Type.
     * @return ColumnType
     */

    public function normalizeColumnType(ColumnType $type): ColumnType
    {
        if (ColumnType::Boolean === $type || $this->isIntegerType($type)) {
            return ColumnType::Int;
        }

        if (in_array($type, [ColumnType::Text, ColumnType::MediumText, ColumnType::LongText, ColumnType::Json], true)) {
            return ColumnType::Text;
        }

        return $type;
    }

    private function isIntegerType(ColumnType $type): bool
    {
        return in_array(
            $type,
            [
                ColumnType::TinyInt,
                ColumnType::SmallInt,
                ColumnType::Int,
                ColumnType::BigInt,
            ],
            true,
        );
    }

    /**
     * Render a CREATE TABLE statement for SQLite.
     *
     * @param TableDefinition $table Table definition to render.
     * @return string
     */
    public function createTableSql(TableDefinition $table): string
    {
        $lines = [];
        $primaryKey = $table->getPrimaryKey();
        $inlinePrimary = null;

        if (count($primaryKey) === 1) {
            $primaryColumn = $table->getColumn($primaryKey[0]);
            if (null !== $primaryColumn && $primaryColumn->autoIncrement) {
                $inlinePrimary = $primaryKey[0];
            }
        }

        foreach ($table->getColumns() as $column) {
            $lines[] = $this->renderColumnDefinition($column, $inlinePrimary === $column->name);
        }

        if (null === $inlinePrimary && !empty($primaryKey)) {
            $lines[] = 'PRIMARY KEY (' . $this->quoteColumns($primaryKey) . ')';
        }

        foreach ($table->getForeignKeys() as $foreignKey) {
            $lines[] = $this->renderForeignKey($foreignKey);
        }

        return 'CREATE TABLE ' . $this->quoteIdentifier($table->name) . " (\n    " . implode(",\n    ", $lines) . "\n)";
    }

    public function dropTableSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
    }

    public function addColumnSql(string $table, ColumnDefinition $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD COLUMN ' . $this->renderColumnDefinition($column, false);
    }

    public function alterColumnSql(string $table, ColumnDefinition $column): string
    {
        return '';
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return '';
    }

    /**
     * Render SQL for creating an index in SQLite.
     *
     * @param string $table Table name.
     * @param IndexDefinition $index Index definition.
     * @return string|array<int,string>
     */
    public function addIndexSql(string $table, IndexDefinition $index): string|array
    {
        $type = strtolower($index->type);
        if ('fulltext' === $type || 'spatial' === $type) {
            throw new RuntimeException('SQLite does not support fulltext/spatial index types in schema renderer.');
        }

        $unique = $index->unique ? 'UNIQUE ' : '';
        $name = $this->resolveIndexName($table, $index);

        $target = null;
        if (null !== $index->expression && '' !== trim($index->expression)) {
            if (!empty($index->columns)) {
                throw new RuntimeException('SQLite index cannot define both columns and expression.');
            }
            $target = trim($index->expression);
        } else {
            if (empty($index->columns)) {
                throw new RuntimeException('SQLite index requires columns or expression.');
            }
            $target = $this->quoteColumns($index->columns);
        }

        $whereSql = '';
        if (null !== $index->where && '' !== trim($index->where)) {
            $whereSql = ' WHERE ' . trim($index->where);
        }

        return (
            'CREATE '
            . $unique
            . 'INDEX '
            . $this->quoteIdentifier($name)
            . ' ON '
            . $this->quoteIdentifier($table)
            . ' ('
            . $target
            . ')'
            . $whereSql
        );
    }

    public function dropIndexSql(string $table, IndexDefinition $index): string|array
    {
        $name = $this->resolveIndexName($table, $index);

        return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($name);
    }

    public function addForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return '';
    }

    public function dropForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return '';
    }

    public function renameTableSql(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($from) . ' RENAME TO ' . $this->quoteIdentifier($to);
    }

    /**
     * Render SQL for renaming a table column.
     * @param string $table Table.
     * @param string $from From.
     * @param string $to To.
     * @return string
     */

    public function renameColumnSql(string $table, string $from, string $to): string
    {
        return (
            'ALTER TABLE '
            . $this->quoteIdentifier($table)
            . ' RENAME COLUMN '
            . $this->quoteIdentifier($from)
            . ' TO '
            . $this->quoteIdentifier($to)
        );
    }

    public function addPrimaryKeySql(string $table, array $columns): string
    {
        return '';
    }

    public function dropPrimaryKeySql(string $table): string
    {
        return '';
    }

    public function supportsAlterColumn(): bool
    {
        return false;
    }

    public function supportsDropColumn(): bool
    {
        return false;
    }

    public function supportsForeignKeys(): bool
    {
        return false;
    }

    public function supportsPrimaryKeyAlter(): bool
    {
        return false;
    }

    /**
     * @return array<int,string>
     */
    public function rebuildTableSql(TableDefinition $from, TableDefinition $to): array
    {
        $temp = '_tmp_' . $from->name . '_old';

        $sql = [];
        $sql[] = 'ALTER TABLE ' . $this->quoteIdentifier($from->name) . ' RENAME TO ' . $this->quoteIdentifier($temp);

        $sql[] = $this->createTableSql($to);

        $columnsToCopy = array_values(array_intersect(
            array_keys($from->getColumns()),
            array_keys($to->getColumns()),
        ));

        if (!empty($columnsToCopy)) {
            $quoted = $this->quoteColumns($columnsToCopy);
            $sql[] =
                'INSERT INTO '
                . $this->quoteIdentifier($to->name)
                . ' ('
                . $quoted
                . ') SELECT '
                . $quoted
                . ' FROM '
                . $this->quoteIdentifier($temp);
        }

        $sql[] = 'DROP TABLE ' . $this->quoteIdentifier($temp);

        foreach ($to->getIndexes() as $index) {
            $sql[] = $this->addIndexSql($to->name, $index);
        }

        return array_values(array_filter($sql, static fn(string $stmt) => '' !== trim($stmt)));
    }

    private function renderColumnDefinition(ColumnDefinition $column, bool $inlinePrimary): string
    {
        $parts = [];
        $parts[] = $this->quoteIdentifier($column->name);

        $typeSql = $this->renderType($column);
        if ($inlinePrimary && $column->autoIncrement) {
            $typeSql = 'INTEGER';
        }

        $parts[] = $typeSql;

        if ($column->generated) {
            if (null === $column->generatedExpression || '' === trim($column->generatedExpression)) {
                throw new RuntimeException('Generated column requires generatedExpression in SQLite.');
            }

            $parts[] = 'GENERATED ALWAYS AS (' . $column->generatedExpression . ')';
            $parts[] = true === $column->generatedStored ? 'STORED' : 'VIRTUAL';
        }

        if ($inlinePrimary) {
            $parts[] = 'PRIMARY KEY';
            if ($column->autoIncrement) {
                $parts[] = 'AUTOINCREMENT';
            }
        }

        $parts[] = $column->nullable ? 'NULL' : 'NOT NULL';

        if ($column->hasDefault) {
            $parts[] = $this->renderDefault($column);
        }

        if (null !== $column->allowed && [] !== $column->allowed) {
            $values = array_map(static fn(mixed $value): string => is_string($value) ? $value : (string) $value, $column->allowed);
            $escaped = array_map($this->quoteLiteral(...), $values);
            $parts[] = 'CHECK (' . $this->quoteIdentifier($column->name) . ' IN (' . implode(', ', $escaped) . '))';
        } elseif ($column->check && null !== $column->checkExpression) {
            $parts[] = 'CHECK (' . $column->checkExpression . ')';
        }

        return implode(' ', $parts);
    }

    private function renderType(ColumnDefinition $column): string
    {
        $type = $this->resolveTypeName($column);
        $suffix = '';

        if (!str_contains($type, '(')) {
            if (null !== $column->precision) {
                $suffix = '(' . $column->precision;
                if (null !== $column->scale) {
                    $suffix .= ',' . $column->scale;
                }
                $suffix .= ')';
            } elseif (null !== $column->length) {
                $suffix = '(' . $column->length . ')';
            }
        }

        return $type . $suffix;
    }

    private function resolveTypeName(ColumnDefinition $column): string
    {
        if (ColumnType::Custom === $column->type) {
            return strtoupper($column->typeName ?? ColumnType::Text->value);
        }

        return match ($column->type) {
            ColumnType::Char => 'CHAR',
            ColumnType::VarChar => 'VARCHAR',
            ColumnType::Text, ColumnType::MediumText, ColumnType::LongText, ColumnType::Json => 'TEXT',
            ColumnType::TinyInt, ColumnType::SmallInt, ColumnType::Int, ColumnType::BigInt, ColumnType::Boolean => 'INTEGER',
            ColumnType::Decimal => 'NUMERIC',
            ColumnType::Float, ColumnType::Double => 'REAL',
            ColumnType::Date => 'DATE',
            ColumnType::DateTime => 'DATETIME',
            ColumnType::Time => 'TIME',
            ColumnType::Timestamp => 'TIMESTAMP',
            ColumnType::Blob => 'BLOB',
            ColumnType::Enum => 'TEXT',
            ColumnType::Set => 'TEXT',
            ColumnType::Binary => 'BLOB',
            ColumnType::Uuid, ColumnType::Ulid => 'TEXT',
            ColumnType::Vector => 'TEXT',
            ColumnType::IpAddress => 'TEXT',
            ColumnType::MacAddress => 'TEXT',
            ColumnType::Geometry => 'TEXT',
            ColumnType::Geography => 'TEXT',
        };
    }

    private function resolveIndexName(string $table, IndexDefinition $index): string
    {
        if (null !== $index->expression && '' !== trim($index->expression)) {
            return $index->name;
        }

        if (empty($index->columns)) {
            return $index->name;
        }

        return NameHelper::indexName($table, $index->columns, $index->unique, 'index');
    }

    private function renderForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        $sql =
            'FOREIGN KEY ('
            . $this->quoteColumns($foreignKey->columns)
            . ')'
            . ' REFERENCES '
            . $this->quoteIdentifier($foreignKey->referencesTable)
            . ' ('
            . $this->quoteColumns($foreignKey->referencesColumns)
            . ')';

        if (null !== $foreignKey->onDelete) {
            $sql .= ' ON DELETE ' . strtoupper($foreignKey->onDelete);
        }
        if (null !== $foreignKey->onUpdate) {
            $sql .= ' ON UPDATE ' . strtoupper($foreignKey->onUpdate);
        }

        return $sql;
    }
}
