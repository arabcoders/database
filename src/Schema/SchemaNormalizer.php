<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Dialect\PostgresDialect;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Schema\Utils\NameHelper;

final class SchemaNormalizer
{
    /**
     * Normalize schema definitions for dialect-aware diffing.
     * @param SchemaDefinition $schema Schema.
     * @param SchemaDialectInterface $dialect Dialect.
     * @return SchemaDefinition
     */
    public function normalize(SchemaDefinition $schema, SchemaDialectInterface $dialect): SchemaDefinition
    {
        $normalized = new SchemaDefinition();

        foreach ($schema->getTables() as $table) {
            $copy = new TableDefinition(
                name: $table->name,
                engine: $this->wrapDriverValue($this->normalizeTableEngine($table->engine, $dialect), $dialect),
                charset: $this->wrapDriverValue($this->normalizeTableCharset($table->charset, $dialect), $dialect),
                collation: $this->wrapDriverValue($this->normalizeTableCollation($table->collation, $dialect), $dialect),
                previousName: $table->previousName,
                sourceClass: $table->sourceClass,
            );

            foreach ($table->getColumns() as $column) {
                $type = $dialect->normalizeColumnType($column->type);
                $length = $column->length;
                if ($this->isIntegerType($type)) {
                    $length = null;
                }

                $allowed = $column->allowed;
                if ($dialect instanceof PostgresDialect && null !== $allowed && [] !== $allowed) {
                    $allowed = array_values($allowed);
                }

                $copy->addColumn(new ColumnDefinition(
                    name: $column->name,
                    type: $type,
                    length: $length,
                    precision: $column->precision,
                    scale: $column->scale,
                    unsigned: $this->normalizeUnsigned($column->unsigned, $dialect),
                    nullable: $column->nullable,
                    autoIncrement: $column->autoIncrement,
                    hasDefault: $column->hasDefault,
                    default: $column->default,
                    defaultIsExpression: $column->defaultIsExpression,
                    charset: $this->wrapDriverValue($this->normalizeColumnCharset($column->charset, $dialect), $dialect),
                    collation: $this->wrapDriverValue($this->normalizeColumnCollation($column->collation, $dialect), $dialect),
                    comment: $column->comment,
                    onUpdate: $column->onUpdate,
                    previousName: $column->previousName,
                    propertyName: $column->propertyName,
                    typeName: $column->typeName,
                    allowed: $allowed,
                    check: $column->check,
                    checkExpression: $this->normalizeExpression($column->checkExpression),
                    generated: $column->generated,
                    generatedExpression: $this->normalizeExpression($column->generatedExpression),
                    generatedStored: $column->generatedStored,
                ));
            }

            foreach ($table->getIndexes() as $index) {
                $name = $index->name;
                $type = $index->type;
                $columns = $index->columns;

                if ($dialect instanceof SqliteDialect) {
                    $name = empty($columns)
                        ? $name
                        : NameHelper::indexName($table->name, $columns, $index->unique, 'index');
                    $type = 'index';
                }

                $algorithmValue = $this->normalizeIndexAlgorithm($index, $dialect);
                $algorithm = [];
                if (null !== $algorithmValue) {
                    $algorithm = [$dialect->name() => $algorithmValue];
                }

                $copy->addIndex(new IndexDefinition(
                    name: $name,
                    columns: $columns,
                    unique: $index->unique,
                    type: $type,
                    algorithm: $algorithm,
                    where: $this->normalizeExpression($index->where),
                    expression: $this->normalizeExpression($index->expression),
                ));
            }

            foreach ($table->getForeignKeys() as $foreignKey) {
                $copy->addForeignKey($foreignKey);
            }

            $primaryKey = $table->getPrimaryKey();
            if (!empty($primaryKey)) {
                $copy->setPrimaryKey($primaryKey);
            }

            $normalized->addTable($copy);
        }

        return $normalized;
    }

    private function normalizeTableEngine(array $engine, SchemaDialectInterface $dialect): ?string
    {
        $engine = $this->resolveDriverValue($engine, $dialect->name());
        $default = $dialect->defaultTableEngine();
        if (null === $engine || null !== $default && $engine === $default) {
            return null;
        }

        return $engine;
    }

    private function normalizeTableCharset(array $charset, SchemaDialectInterface $dialect): ?string
    {
        $charset = $this->resolveDriverValue($charset, $dialect->name());
        $default = $dialect->defaultTableCharset();
        if (null === $charset || null !== $default && $charset === $default) {
            return null;
        }

        return $charset;
    }

    private function normalizeTableCollation(array $collation, SchemaDialectInterface $dialect): ?string
    {
        $collation = $this->resolveDriverValue($collation, $dialect->name());
        $default = $dialect->defaultTableCollation();
        if (null === $collation || null !== $default && $collation === $default) {
            return null;
        }

        return $collation;
    }

    private function normalizeColumnCharset(array $charset, SchemaDialectInterface $dialect): ?string
    {
        if (!$dialect instanceof MysqlDialect) {
            return null;
        }
        $charset = $this->resolveDriverValue($charset, $dialect->name());
        $default = $dialect->defaultTableCharset();
        if (null === $charset || null !== $default && $charset === $default) {
            return null;
        }

        return $charset;
    }

    private function normalizeColumnCollation(array $collation, SchemaDialectInterface $dialect): ?string
    {
        if (!$dialect instanceof MysqlDialect) {
            return null;
        }
        $collation = $this->resolveDriverValue($collation, $dialect->name());
        $default = $dialect->defaultTableCollation();
        if (null === $collation || null !== $default && $collation === $default) {
            return null;
        }

        return $collation;
    }

    private function normalizeIndexAlgorithm(IndexDefinition $index, SchemaDialectInterface $dialect): ?string
    {
        $algorithm = $this->resolveDriverValue($index->algorithm, $dialect->name());

        if ($dialect instanceof PostgresDialect && $index->unique && null !== $algorithm && 'btree' !== strtolower($algorithm)) {
            $algorithm = 'btree';
        }

        $default = $dialect->defaultIndexAlgorithm($index);
        if (null === $algorithm || null !== $default && strtolower($algorithm) === strtolower($default)) {
            return null;
        }

        return strtolower($algorithm);
    }

    private function normalizeUnsigned(bool $unsigned, SchemaDialectInterface $dialect): bool
    {
        if ($dialect instanceof MysqlDialect) {
            return $unsigned;
        }

        return false;
    }

    private function resolveDriverValue(array $value, string $driver): ?string
    {
        if ([] === $value) {
            return null;
        }

        if (array_key_exists($driver, $value)) {
            $driverValue = $value[$driver];
            return is_string($driverValue) ? $driverValue : null;
        }

        if (array_key_exists('default', $value)) {
            $defaultValue = $value['default'];
            return is_string($defaultValue) ? $defaultValue : null;
        }

        return null;
    }

    private function wrapDriverValue(?string $value, SchemaDialectInterface $dialect): array
    {
        if (null === $value) {
            return [];
        }

        return [$dialect->name() => $value];
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

    private function normalizeExpression(?string $expression): ?string
    {
        if (null === $expression) {
            return null;
        }

        $expression = trim($expression);
        if ('' === $expression) {
            return null;
        }

        return $expression;
    }
}
