<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;

abstract class AbstractSchemaDialect implements SchemaDialectInterface
{
    public function __construct(
        protected DatabaseDialectInterface $dialect,
    ) {}

    public function quoteIdentifier(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }

    /**
     * @param array<int,string> $columns
     */
    protected function quoteColumns(array $columns): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $columns));
    }

    protected function quoteLiteral(string $value): string
    {
        return $this->dialect->quoteString($value);
    }

    protected function renderDefault(ColumnDefinition $column): string
    {
        if ($column->defaultIsExpression) {
            return 'DEFAULT ' . (string) $column->default;
        }

        if (null === $column->default) {
            return 'DEFAULT NULL';
        }

        if (is_bool($column->default)) {
            return 'DEFAULT ' . ($column->default ? '1' : '0');
        }

        if (is_int($column->default) || is_float($column->default)) {
            return 'DEFAULT ' . $column->default;
        }

        return 'DEFAULT ' . $this->quoteLiteral((string) $column->default);
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

    public function normalizeColumnType(ColumnType $type): ColumnType
    {
        return $type;
    }
}
