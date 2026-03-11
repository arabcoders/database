<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use arabcoders\database\Dialect\PostgresDialect as DatabasePostgresDialect;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Utils\NameHelper;
use RuntimeException;

final class PostgresDialect extends AbstractSchemaDialect
{
    public function __construct(
        DatabaseDialectInterface $dialect = new DatabasePostgresDialect(),
    ) {
        parent::__construct($dialect);
    }

    public function name(): string
    {
        return 'pgsql';
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

    /**
     * Resolve the default index algorithm for the index definition.
     * @param IndexDefinition $index Index.
     * @return ?string
     */

    public function defaultIndexAlgorithm(IndexDefinition $index): ?string
    {
        $type = strtolower($index->type);
        if (in_array($type, ['fulltext', 'spatial'], true)) {
            return null;
        }

        return 'btree';
    }

    /**
     * Normalize a logical column type for the current SQL dialect.
     * @param ColumnType $type Type.
     * @return ColumnType
     */

    public function normalizeColumnType(ColumnType $type): ColumnType
    {
        if (ColumnType::TinyInt === $type) {
            return ColumnType::SmallInt;
        }

        if (in_array($type, [ColumnType::MediumText, ColumnType::LongText], true)) {
            return ColumnType::Text;
        }

        return $type;
    }

    /**
     * Render a CREATE TABLE statement including inline primary and foreign key clauses.
     *
     * @param TableDefinition $table Table definition to render.
     * @return string
     */
    public function createTableSql(TableDefinition $table): string
    {
        $lines = [];
        foreach ($table->getColumns() as $column) {
            $lines[] = $this->renderColumnDefinition($column);
        }

        $primaryKey = $table->getPrimaryKey();
        if (!empty($primaryKey)) {
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
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD COLUMN ' . $this->renderColumnDefinition($column);
    }

    /**
     * Render SQL for altering an existing column definition.
     * @param string $table Table.
     * @param ColumnDefinition $column Column.
     * @return string
     */

    public function alterColumnSql(string $table, ColumnDefinition $column): string
    {
        $parts = [];

        $typeSql = $this->renderType($column);

        $name = $this->quoteIdentifier($column->name);
        $parts[] = 'ALTER COLUMN ' . $name . ' TYPE ' . $typeSql;
        $parts[] = 'ALTER COLUMN ' . $name . ($column->nullable ? ' DROP NOT NULL' : ' SET NOT NULL');

        if (!$column->autoIncrement) {
            if ($column->hasDefault) {
                $parts[] = 'ALTER COLUMN ' . $name . ' SET DEFAULT ' . $this->renderDefaultExpression($column);
            } else {
                $parts[] = 'ALTER COLUMN ' . $name . ' DROP DEFAULT';
            }
        }

        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ' . implode(', ', $parts);
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP COLUMN ' . $this->quoteIdentifier($column);
    }

    /**
     * Render SQL for creating an index, including PostgreSQL-specific GIN/GIST/fulltext handling.
     *
     * @param string $table Table name.
     * @param IndexDefinition $index Index definition.
     * @return string|array<int,string>
     * @throws \RuntimeException If index options are invalid for the selected method.
     */
    public function addIndexSql(string $table, IndexDefinition $index): string|array
    {
        $whereSql = '';
        if (null !== $index->where && '' !== trim($index->where)) {
            $whereSql = ' WHERE ' . trim($index->where);
        }

        $type = strtolower($index->type);
        if ('fulltext' === $type) {
            if (null !== $index->where && '' !== trim($index->where)) {
                throw new RuntimeException('PostgreSQL fulltext indexes do not support WHERE with this renderer.');
            }

            if (null !== $index->expression && '' !== trim($index->expression)) {
                throw new RuntimeException('PostgreSQL fulltext indexes do not support custom expression with this renderer.');
            }

            return $this->createFulltextIndexSql($table, $index);
        }

        $target = $this->renderIndexTarget($index);

        $method = $this->resolveIndexMethod($index);
        if (null === $method) {
            $method = 'btree';
        }

        $indexName = $this->normalizeIndexName($table, $index);

        if (in_array($method, ['gin', 'gist'], true)) {
            return $this->createGinGistIndexSql($table, $index, $method, $target, $whereSql);
        }

        // PostgreSQL only supports UNIQUE on btree indexes.
        // For unique indexes with non-btree algorithm, fall back to btree.
        if ($index->unique && 'btree' !== $method) {
            $method = 'btree';
        }

        $using = ' USING ' . strtoupper($method);

        return (
            'CREATE '
            . ($index->unique ? 'UNIQUE ' : '')
            . 'INDEX '
            . $this->quoteIdentifier($indexName)
            . ' ON '
            . $this->quoteIdentifier($table)
            . $using
            . ' ('
            . $target
            . ')'
            . $whereSql
        );
    }

    public function dropIndexSql(string $table, IndexDefinition $index): string|array
    {
        $indexName = $this->normalizeIndexName($table, $index);

        return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($indexName);
    }

    private function assertGinGistCompatible(IndexDefinition $index): void
    {
        if (null !== $index->expression && '' !== trim($index->expression)) {
            return;
        }

        if (empty($index->columns)) {
            throw new \RuntimeException('GIN/GIST indexes require at least one column.');
        }

        $type = strtolower($index->type);
        if ('spatial' === $type && count($index->columns) !== 1) {
            throw new \RuntimeException('Spatial GIST indexes require a single column.');
        }

        if (count($index->columns) !== 1) {
            throw new \RuntimeException('GIN/GIST indexes require a single column for default operator classes.');
        }
    }

    private function createGinGistIndexSql(
        string $table,
        IndexDefinition $index,
        string $method,
        string $target,
        string $whereSql,
    ): string {
        $this->assertGinGistCompatible($index);

        $indexName = $this->normalizeIndexName($table, $index);

        return (
            'CREATE '
            . ($index->unique ? 'UNIQUE ' : '')
            . 'INDEX '
            . $this->quoteIdentifier($indexName)
            . ' ON '
            . $this->quoteIdentifier($table)
            . ' USING '
            . strtoupper($method)
            . ' ('
            . $target
            . ')'
            . $whereSql
        );
    }

    private function normalizeIndexName(string $table, IndexDefinition $index): string
    {
        if ('' !== trim($index->name)) {
            return $index->name;
        }

        $type = strtolower($index->type);
        return NameHelper::indexName(
            $table,
            $index->columns,
            $index->unique,
            $type,
        );
    }

    private function createFulltextIndexSql(string $table, IndexDefinition $index): string
    {
        if (empty($index->columns)) {
            throw new \RuntimeException('Fulltext indexes require at least one column.');
        }

        $language = 'english';
        $vectors = [];
        foreach ($index->columns as $column) {
            $vectors[] = 'to_tsvector(' . $this->quoteLiteral($language) . ', ' . $this->quoteIdentifier($column) . ')';
        }

        $indexName = $this->normalizeIndexName($table, $index);
        $expression = implode(' || ', $vectors);

        return (
            'CREATE '
            . ($index->unique ? 'UNIQUE ' : '')
            . 'INDEX '
            . $this->quoteIdentifier($indexName)
            . ' ON '
            . $this->quoteIdentifier($table)
            . ' USING GIN '
            . '(('
            . $expression
            . '))'
        );
    }

    /**
     * Render SQL for adding a foreign key constraint.
     * @param string $table Table.
     * @param ForeignKeyDefinition $foreignKey Foreign key.
     * @return string
     */

    public function addForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string
    {
        $sql =
            'ALTER TABLE '
            . $this->quoteIdentifier($table)
            . ' ADD CONSTRAINT '
            . $this->quoteIdentifier($foreignKey->name)
            . ' FOREIGN KEY ('
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

    public function dropForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($foreignKey->name);
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
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD PRIMARY KEY (' . $this->quoteColumns($columns) . ')';
    }

    public function dropPrimaryKeySql(string $table): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($table . '_pkey');
    }

    public function supportsAlterColumn(): bool
    {
        return true;
    }

    public function supportsDropColumn(): bool
    {
        return true;
    }

    public function supportsForeignKeys(): bool
    {
        return true;
    }

    public function supportsPrimaryKeyAlter(): bool
    {
        return true;
    }

    protected function renderDefault(ColumnDefinition $column): string
    {
        return 'DEFAULT ' . $this->renderDefaultExpression($column);
    }

    private function renderDefaultExpression(ColumnDefinition $column): string
    {
        if ($column->defaultIsExpression) {
            return (string) $column->default;
        }

        if (null === $column->default) {
            return 'NULL';
        }

        if (is_bool($column->default)) {
            return $column->default ? 'TRUE' : 'FALSE';
        }

        if (is_int($column->default) || is_float($column->default)) {
            return (string) $column->default;
        }

        return $this->quoteLiteral((string) $column->default);
    }

    private function renderColumnDefinition(ColumnDefinition $column): string
    {
        $parts = [];
        $parts[] = $this->quoteIdentifier($column->name);
        $parts[] = $this->renderType($column);

        if ($column->generated) {
            if (null === $column->generatedExpression || '' === trim($column->generatedExpression)) {
                throw new RuntimeException('Generated column requires generatedExpression in PostgreSQL.');
            }

            if (false === $column->generatedStored) {
                throw new RuntimeException('PostgreSQL supports only STORED generated columns.');
            }

            $parts[] = 'GENERATED ALWAYS AS (' . $column->generatedExpression . ') STORED';
        }

        $parts[] = $column->nullable ? 'NULL' : 'NOT NULL';

        if ($column->hasDefault && !$column->autoIncrement) {
            $parts[] = $this->renderDefault($column);
        }

        if ($column->autoIncrement) {
            $parts[] = 'GENERATED BY DEFAULT AS IDENTITY';
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
        $length = $column->length;
        $precision = $column->precision;
        $scale = $column->scale;

        if ($this->isIntegerType($column->type)) {
            $length = null;
            $precision = null;
            $scale = null;
        }
        $suffix = '';

        if (!str_contains($type, '(')) {
            if (ColumnType::Uuid === $column->type || ColumnType::Ulid === $column->type) {
                $length = null;
            }

            if (null !== $precision) {
                $suffix = '(' . $precision;
                if (null !== $scale) {
                    $suffix .= ',' . $scale;
                }
                $suffix .= ')';
            } elseif (null !== $length) {
                $suffix = '(' . $length . ')';
            }
        }

        return $type . $suffix;
    }

    private function resolveTypeName(ColumnDefinition $column): string
    {
        if (ColumnType::Custom === $column->type) {
            return $column->typeName ?? ColumnType::Text->value;
        }

        return match ($column->type) {
            ColumnType::Char => 'char',
            ColumnType::VarChar => 'varchar',
            ColumnType::Text, ColumnType::MediumText, ColumnType::LongText => 'text',
            ColumnType::TinyInt, ColumnType::SmallInt => 'smallint',
            ColumnType::Int => 'integer',
            ColumnType::BigInt => 'bigint',
            ColumnType::Decimal => 'numeric',
            ColumnType::Float => 'real',
            ColumnType::Double => 'double precision',
            ColumnType::Boolean => 'boolean',
            ColumnType::Date => 'date',
            ColumnType::DateTime => 'timestamp',
            ColumnType::Time => 'time',
            ColumnType::Timestamp => 'timestamptz',
            ColumnType::Json => 'jsonb',
            ColumnType::Blob => 'bytea',
            ColumnType::Enum => 'text',
            ColumnType::Set => 'text',
            ColumnType::Binary => 'bytea',
            ColumnType::Uuid, ColumnType::Ulid => 'uuid',
            ColumnType::Vector => 'vector',
            ColumnType::IpAddress => 'inet',
            ColumnType::MacAddress => 'macaddr',
            ColumnType::Geometry => 'geometry',
            ColumnType::Geography => 'geography',
        };
    }

    private function resolveIndexMethod(IndexDefinition $index): ?string
    {
        if ([] !== $index->algorithm && array_key_exists('pgsql', $index->algorithm)) {
            $algorithm = $index->algorithm['pgsql'];
            if (is_string($algorithm) && '' !== trim($algorithm)) {
                return strtolower($algorithm);
            }
        }

        $type = strtolower($index->type);
        if (in_array($type, ['fulltext', 'spatial'], true)) {
            return null;
        }

        return null;
    }

    private function renderIndexTarget(IndexDefinition $index): string
    {
        if (null !== $index->expression && '' !== trim($index->expression)) {
            if (!empty($index->columns)) {
                throw new RuntimeException('PostgreSQL index cannot define both columns and expression.');
            }

            return trim($index->expression);
        }

        if (empty($index->columns)) {
            throw new RuntimeException('PostgreSQL index requires columns or expression.');
        }

        return $this->quoteColumns($index->columns);
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

    private function renderForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        $sql =
            'CONSTRAINT '
            . $this->quoteIdentifier($foreignKey->name)
            . ' FOREIGN KEY ('
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
