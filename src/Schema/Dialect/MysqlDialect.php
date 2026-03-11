<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use arabcoders\database\Dialect\MysqlDialect as DatabaseMysqlDialect;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use RuntimeException;

final class MysqlDialect extends AbstractSchemaDialect
{
    public function __construct(
        DatabaseDialectInterface $dialect = new DatabaseMysqlDialect(),
    ) {
        parent::__construct($dialect);
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function defaultTableEngine(): ?string
    {
        return 'InnoDB';
    }

    public function defaultTableCharset(): ?string
    {
        return 'utf8mb4';
    }

    public function defaultTableCollation(): ?string
    {
        return 'utf8mb4_unicode_ci';
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
        if ($this->isMariaDbJsonType($type)) {
            return ColumnType::LongText;
        }

        if (ColumnType::Boolean === $type) {
            return ColumnType::TinyInt;
        }

        return $type;
    }

    /**
     * Render a CREATE TABLE statement with MySQL engine/charset/collation options.
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

        $sql = 'CREATE TABLE ' . $this->quoteIdentifier($table->name) . " (\n    " . implode(",\n    ", $lines) . "\n)";

        $options = [];
        if ([] !== $table->engine && array_key_exists('mysql', $table->engine)) {
            $engine = $table->engine['mysql'];
            if (is_string($engine) && '' !== $engine) {
                $options[] = 'ENGINE=' . $engine;
            }
        }
        if ([] !== $table->charset && array_key_exists('mysql', $table->charset)) {
            $charset = $table->charset['mysql'];
            if (is_string($charset) && '' !== $charset) {
                $options[] = 'DEFAULT CHARSET=' . $charset;
            }
        }
        if ([] !== $table->collation && array_key_exists('mysql', $table->collation)) {
            $collation = $table->collation['mysql'];
            if (is_string($collation) && '' !== $collation) {
                $options[] = 'COLLATE=' . $collation;
            }
        }

        if (!empty($options)) {
            $sql .= ' ' . implode(' ', $options);
        }

        return $sql;
    }

    public function dropTableSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
    }

    public function addColumnSql(string $table, ColumnDefinition $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD COLUMN ' . $this->renderColumnDefinition($column);
    }

    public function alterColumnSql(string $table, ColumnDefinition $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' MODIFY COLUMN ' . $this->renderColumnDefinition($column);
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP COLUMN ' . $this->quoteIdentifier($column);
    }

    /**
     * Render SQL for creating an index, including MySQL fulltext and spatial forms.
     *
     * @param string $table Table name.
     * @param IndexDefinition $index Index definition.
     * @return string|array<int,string>
     */
    public function addIndexSql(string $table, IndexDefinition $index): string|array
    {
        if (null !== $index->where) {
            throw new RuntimeException('MySQL does not support partial indexes with WHERE predicates.');
        }

        $type = strtolower($index->type);
        $unique = $index->unique ? 'UNIQUE ' : '';
        $prefix = '';

        if ('fulltext' === $type) {
            $prefix = 'FULLTEXT ';
            $unique = '';
        } elseif ('spatial' === $type) {
            $prefix = 'SPATIAL ';
            $unique = '';
        }

        if (null !== $index->expression && '' !== trim($index->expression)) {
            if ('index' !== $type) {
                throw new RuntimeException('MySQL expression indexes are only supported for regular indexes.');
            }

            if (!empty($index->columns)) {
                throw new RuntimeException('MySQL expression indexes cannot define both columns and expression.');
            }
        }

        if ((null === $index->expression || '' === trim($index->expression)) && empty($index->columns)) {
            throw new RuntimeException('MySQL index requires columns or expression.');
        }

        $algorithm = '';
        if ([] !== $index->algorithm && array_key_exists('mysql', $index->algorithm)) {
            $value = $index->algorithm['mysql'];
            if (is_string($value) && '' !== $value) {
                $algorithm = ' USING ' . strtoupper($value);
            }
        }

        $target = null;
        if (null !== $index->expression && '' !== trim($index->expression)) {
            $target = '(' . trim($index->expression) . ')';
        } else {
            $target = $this->quoteColumns($index->columns);
        }

        return (
            'CREATE '
            . $prefix
            . $unique
            . 'INDEX '
            . $this->quoteIdentifier($index->name)
            . $algorithm
            . ' ON '
            . $this->quoteIdentifier($table)
            . ' ('
            . $target
            . ')'
        );
    }

    public function dropIndexSql(string $table, IndexDefinition $index): string|array
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($index->name) . ' ON ' . $this->quoteIdentifier($table);
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
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($foreignKey->name);
    }

    public function renameTableSql(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->quoteIdentifier($from) . ' TO ' . $this->quoteIdentifier($to);
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
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP PRIMARY KEY';
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

    private function renderColumnDefinition(ColumnDefinition $column): string
    {
        $parts = [];
        $parts[] = $this->quoteIdentifier($column->name);
        $parts[] = $this->renderType($column);

        if ($column->generated) {
            if (null === $column->generatedExpression || '' === trim($column->generatedExpression)) {
                throw new RuntimeException('Generated column requires generatedExpression in MySQL.');
            }

            $parts[] = 'GENERATED ALWAYS AS (' . $column->generatedExpression . ')';
            $parts[] = true === $column->generatedStored ? 'STORED' : 'VIRTUAL';
        }

        $parts[] = $column->nullable ? 'NULL' : 'NOT NULL';

        if ($column->hasDefault) {
            $parts[] = $this->renderDefault($column);
        }

        if ($column->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if (null !== $column->comment) {
            $parts[] = 'COMMENT ' . $this->quoteLiteral($column->comment);
        }

        if (null !== $column->onUpdate) {
            $parts[] = 'ON UPDATE ' . $column->onUpdate;
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

    private function isMariaDbJsonType(ColumnType $type): bool
    {
        if (!$this->dialect instanceof DatabaseMysqlDialect) {
            return false;
        }

        if (!$this->dialect->isMariaDb()) {
            return false;
        }

        return ColumnType::Json === $type;
    }

    private function renderType(ColumnDefinition $column): string
    {
        $type = $this->resolveTypeName($column);
        $suffix = '';
        $length = $column->length;

        if (ColumnType::Boolean === $column->type && null === $length) {
            $length = 1;
        }

        if (!str_contains($type, '(')) {
            if (ColumnType::Enum === $column->type || ColumnType::Set === $column->type) {
                $allowed = $column->allowed ?? [];
                $values = array_map(static fn(mixed $value): string => is_string($value) ? $value : (string) $value, $allowed);
                $escaped = array_map($this->quoteLiteral(...), $values);
                $suffix = '(' . implode(', ', $escaped) . ')';
            } elseif (ColumnType::Uuid === $column->type || ColumnType::Ulid === $column->type) {
                $length = ColumnType::Uuid === $column->type ? 36 : 26;
            } elseif (ColumnType::IpAddress === $column->type) {
                $length = 45;
            } elseif (ColumnType::MacAddress === $column->type) {
                $length = 17;
            }

            if (null !== $column->precision) {
                $suffix = '(' . $column->precision;
                if (null !== $column->scale) {
                    $suffix .= ',' . $column->scale;
                }
                $suffix .= ')';
            } elseif (null !== $length) {
                $suffix = '(' . $length . ')';
            }
        }

        $typeSql = $type . $suffix;

        if ($column->unsigned) {
            $typeSql .= ' unsigned';
        }

        if ([] !== $column->charset && array_key_exists('mysql', $column->charset)) {
            $charset = $column->charset['mysql'];
            if (is_string($charset) && '' !== $charset) {
                $typeSql .= ' CHARACTER SET ' . $charset;
            }
        }

        if ([] !== $column->collation && array_key_exists('mysql', $column->collation)) {
            $collation = $column->collation['mysql'];
            if (is_string($collation) && '' !== $collation) {
                $typeSql .= ' COLLATE ' . $collation;
            }
        }

        return $typeSql;
    }

    private function resolveTypeName(ColumnDefinition $column): string
    {
        if (ColumnType::Custom === $column->type) {
            return $column->typeName ?? ColumnType::Text->value;
        }

        return match ($column->type) {
            ColumnType::Boolean => ColumnType::TinyInt->value,
            ColumnType::Enum => 'enum',
            ColumnType::Set => 'set',
            ColumnType::Binary => 'binary',
            ColumnType::Uuid, ColumnType::Ulid => 'char',
            ColumnType::Vector => 'vector',
            ColumnType::IpAddress, ColumnType::MacAddress => 'varchar',
            ColumnType::Geometry => 'geometry',
            ColumnType::Geography => 'geography',
            default => $column->type->value,
        };
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
