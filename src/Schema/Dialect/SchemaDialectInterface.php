<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;

interface SchemaDialectInterface
{
    public function name(): string;

    public function quoteIdentifier(string $identifier): string;

    public function createTableSql(TableDefinition $table): string;

    public function dropTableSql(string $table): string;

    public function addColumnSql(string $table, ColumnDefinition $column): string;

    public function alterColumnSql(string $table, ColumnDefinition $column): string;

    public function dropColumnSql(string $table, string $column): string;

    /**
     * @return string|array<int,string>
     */
    public function addIndexSql(string $table, IndexDefinition $index): string|array;

    /**
     * @return string|array<int,string>
     */
    public function dropIndexSql(string $table, IndexDefinition $index): string|array;

    public function addForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string;

    public function dropForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string;

    public function renameTableSql(string $from, string $to): string;

    public function renameColumnSql(string $table, string $from, string $to): string;

    /**
     * @param array<int,string> $columns
     */
    public function addPrimaryKeySql(string $table, array $columns): string;

    public function dropPrimaryKeySql(string $table): string;

    public function defaultTableEngine(): ?string;

    public function defaultTableCharset(): ?string;

    public function defaultTableCollation(): ?string;

    public function defaultIndexAlgorithm(IndexDefinition $index): ?string;

    public function normalizeColumnType(ColumnType $type): ColumnType;

    public function supportsAlterColumn(): bool;

    public function supportsDropColumn(): bool;

    public function supportsForeignKeys(): bool;

    public function supportsPrimaryKeyAlter(): bool;
}
