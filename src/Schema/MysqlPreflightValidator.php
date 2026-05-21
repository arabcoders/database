<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\AddForeignKeyOperation;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\AlterColumnOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropForeignKeyOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use RuntimeException;

final readonly class MysqlPreflightValidator
{
    private const int IDENTIFIER_LIMIT = 64;
    private const int MAX_KEY_BYTES = 3072;

    public function __construct(
        private MysqlDialect $dialect,
    ) {}

    public function validate(SchemaDiff $diff): void
    {
        $operations = $diff->getOperations();
        if ([] === $operations) {
            return;
        }

        $this->validateOperationIdentifiers($operations);

        foreach ($this->collectTargetTables($diff) as $table) {
            $this->validateTable($table);
        }
    }

    /**
     * @param array<int,mixed> $operations
     */
    private function validateOperationIdentifiers(array $operations): void
    {
        foreach ($operations as $operation) {
            if ($operation instanceof RenameTableOperation) {
                $this->assertIdentifierLength('table', $operation->to);
                continue;
            }

            if ($operation instanceof RenameColumnOperation) {
                $this->assertIdentifierLength('column', $operation->to, $operation->table);
                continue;
            }

            if ($operation instanceof AddColumnOperation || $operation instanceof AlterColumnOperation) {
                $column = $operation instanceof AddColumnOperation ? $operation->column : $operation->to;
                $this->assertIdentifierLength('column', $column->name, $operation->table);
                continue;
            }

            if ($operation instanceof AddIndexOperation || $operation instanceof DropIndexOperation) {
                $this->assertIdentifierLength('index', $operation->index->name, $operation->table);
                continue;
            }

            if ($operation instanceof AddForeignKeyOperation || $operation instanceof DropForeignKeyOperation) {
                $this->assertIdentifierLength('foreign key', $operation->foreignKey->name, $operation->table);
            }
        }
    }

    /**
     * @return array<string,TableDefinition>
     */
    private function collectTargetTables(SchemaDiff $diff): array
    {
        $tables = [];

        foreach ($diff->getOperations() as $operation) {
            if ($operation instanceof CreateTableOperation) {
                $tables[$operation->table->name] = $operation->table;
                continue;
            }

            if ($operation instanceof RenameTableOperation) {
                $table = $diff->to->getTable($operation->to);
                if (null !== $table) {
                    $tables[$table->name] = $table;
                }
                continue;
            }

            $tableName = $operation->getTableName();
            if (null === $tableName || isset($tables[$tableName])) {
                continue;
            }

            $table = $diff->to->getTable($tableName);
            if (null !== $table) {
                $tables[$tableName] = $table;
            }
        }

        return $tables;
    }

    private function validateTable(TableDefinition $table): void
    {
        $this->assertIdentifierLength('table', $table->name);

        foreach ($table->getColumns() as $column) {
            $this->assertIdentifierLength('column', $column->name, $table->name);
        }

        foreach ($table->getIndexes() as $index) {
            $this->validateIndex($table, $index);
        }

        foreach ($table->getForeignKeys() as $foreignKey) {
            $this->assertIdentifierLength('foreign key', $foreignKey->name, $table->name);
            $this->assertIdentifierLength('table', $foreignKey->referencesTable);
            foreach ($foreignKey->referencesColumns as $column) {
                $this->assertIdentifierLength('column', $column, $foreignKey->referencesTable);
            }
        }

        $primaryKey = $table->getPrimaryKey();
        if ([] === $primaryKey) {
            return;
        }

        $bytes = $this->estimateKeyBytes($table, $primaryKey, 'PRIMARY');
        if (null !== $bytes && $bytes > self::MAX_KEY_BYTES) {
            throw new RuntimeException(sprintf(
                'MySQL primary key on table "%s" is estimated at %d bytes, which exceeds the %d-byte key limit. Reduce indexed string lengths or use fewer columns.',
                $table->name,
                $bytes,
                self::MAX_KEY_BYTES,
            ));
        }
    }

    private function validateIndex(TableDefinition $table, IndexDefinition $index): void
    {
        $this->assertIdentifierLength('index', $index->name, $table->name);

        $type = strtolower($index->type);
        if ('fulltext' === $type || 'spatial' === $type) {
            return;
        }

        $bytes = $this->estimateKeyBytes($table, $index->columns, $index->name);
        if (null === $bytes || $bytes <= self::MAX_KEY_BYTES) {
            return;
        }

        throw new RuntimeException(sprintf(
            'MySQL index "%s" on table "%s" is estimated at %d bytes, which exceeds the %d-byte key limit. Reduce indexed string lengths, change charset, or index fewer columns.',
            $index->name,
            $table->name,
            $bytes,
            self::MAX_KEY_BYTES,
        ));
    }

    /**
     * @param array<int,string> $columns
     */
    private function estimateKeyBytes(TableDefinition $table, array $columns, string $keyName): ?int
    {
        if ([] === $columns) {
            return null;
        }

        $total = 0;
        foreach ($columns as $columnName) {
            $column = $table->getColumn($columnName);
            if (null === $column) {
                throw new RuntimeException(sprintf(
                    'MySQL key "%s" on table "%s" references unknown column "%s".',
                    $keyName,
                    $table->name,
                    $columnName,
                ));
            }

            $estimated = $this->estimateColumnBytes($table, $column, $keyName);
            if (null === $estimated) {
                return null;
            }

            $total += $estimated;
        }

        return $total;
    }

    private function estimateColumnBytes(TableDefinition $table, ColumnDefinition $column, string $keyName): ?int
    {
        return match ($column->type) {
            ColumnType::TinyInt, ColumnType::Boolean => 1,
            ColumnType::SmallInt => 2,
            ColumnType::Int => 4,
            ColumnType::BigInt => 8,
            ColumnType::Float => 4,
            ColumnType::Double => 8,
            ColumnType::Decimal => $this->decimalBytes($column),
            ColumnType::Date => 3,
            ColumnType::Time => 3,
            ColumnType::Timestamp => 4,
            ColumnType::DateTime => 8,
            ColumnType::Char, ColumnType::VarChar => $this->characterBytes($table, $column),
            ColumnType::Binary => $this->binaryBytes($column),
            ColumnType::Enum => count($column->allowed ?? []) > 255 ? 2 : 1,
            ColumnType::Set => 8,
            ColumnType::Uuid => ($column->length ?? 36) * $this->charsetBytes($this->columnCharset($table, $column)),
            ColumnType::Ulid => ($column->length ?? 26) * $this->charsetBytes($this->columnCharset($table, $column)),
            ColumnType::IpAddress => ($column->length ?? 45) * $this->charsetBytes($this->columnCharset($table, $column)),
            ColumnType::MacAddress => ($column->length ?? 17) * $this->charsetBytes($this->columnCharset($table, $column)),
            ColumnType::Text,
            ColumnType::MediumText,
            ColumnType::LongText,
            ColumnType::Json,
            ColumnType::Blob,
                => throw new RuntimeException(sprintf(
                'MySQL key "%s" on table "%s" cannot fully index column "%s" of type "%s" without a prefix length. Prefix lengths are not supported by this renderer.',
                $keyName,
                $table->name,
                $column->name,
                $column->type->value,
            )),
            ColumnType::Custom => $this->customTypeBytes($table, $column, $keyName),
            default => null,
        };
    }

    private function characterBytes(TableDefinition $table, ColumnDefinition $column): int
    {
        $length = $column->length;
        if (null === $length || $length <= 0) {
            throw new RuntimeException(sprintf(
                'MySQL indexed column "%s" on table "%s" requires an explicit positive length.',
                $column->name,
                $table->name,
            ));
        }

        return $length * $this->charsetBytes($this->columnCharset($table, $column));
    }

    private function binaryBytes(ColumnDefinition $column): int
    {
        $length = $column->length;
        return null !== $length && $length > 0 ? $length : 1;
    }

    private function decimalBytes(ColumnDefinition $column): int
    {
        $precision = $column->precision ?? 10;
        $scale = $column->scale ?? 0;
        $digits = max($precision, $scale);

        return (int) ceil($digits / 9) * 4;
    }

    private function customTypeBytes(TableDefinition $table, ColumnDefinition $column, string $keyName): ?int
    {
        $typeName = strtolower(trim((string) ($column->typeName ?? '')));
        if ('' === $typeName) {
            return null;
        }

        if (1 === preg_match('/^[a-z0-9_]+/', $typeName, $matches)) {
            $typeName = $matches[0];
        }

        return match ($typeName) {
            'char', 'varchar' => $this->characterBytes($table, $column),
            'binary', 'varbinary' => $this->binaryBytes($column),
            'text',
            'tinytext',
            'mediumtext',
            'longtext',
            'json',
            'blob',
            'tinyblob',
            'mediumblob',
            'longblob',
                => throw new RuntimeException(sprintf(
                'MySQL key "%s" on table "%s" cannot fully index column "%s" of type "%s" without a prefix length. Prefix lengths are not supported by this renderer.',
                $keyName,
                $table->name,
                $column->name,
                $typeName,
            )),
            'enum' => count($column->allowed ?? []) > 255 ? 2 : 1,
            'set' => 8,
            default => null,
        };
    }

    private function columnCharset(TableDefinition $table, ColumnDefinition $column): string
    {
        $charset = $this->resolveDriverValue($column->charset);
        if (null !== $charset) {
            return strtolower($charset);
        }

        $charset = $this->charsetFromCollation($this->resolveDriverValue($column->collation));
        if (null !== $charset) {
            return $charset;
        }

        $charset = $this->resolveDriverValue($table->charset);
        if (null !== $charset) {
            return strtolower($charset);
        }

        $charset = $this->charsetFromCollation($this->resolveDriverValue($table->collation));
        if (null !== $charset) {
            return $charset;
        }

        return strtolower($this->dialect->defaultTableCharset() ?? 'utf8mb4');
    }

    private function charsetBytes(string $charset): int
    {
        return match (strtolower($charset)) {
            'ascii',
            'binary',
            'cp850',
            'cp852',
            'cp866',
            'dec8',
            'geostd8',
            'hp8',
            'koi8r',
            'koi8u',
            'latin1',
            'latin2',
            'latin5',
            'latin7',
            'macce',
            'macroman',
            'swe7',
            'armscii8',
            'hebrew',
            'keybcs2',
            'tis620',
                => 1,
            'big5', 'cp932', 'eucjpms', 'euckr', 'gb2312', 'gbk', 'sjis', 'ucs2' => 2,
            'utf8', 'utf8mb3', 'ujis' => 3,
            default => 4,
        };
    }

    private function charsetFromCollation(?string $collation): ?string
    {
        if (null === $collation) {
            return null;
        }

        $collation = strtolower(trim($collation));
        if ('' === $collation || !str_contains($collation, '_')) {
            return null;
        }

        return explode('_', $collation, 2)[0];
    }

    private function resolveDriverValue(array $value): ?string
    {
        if ([] === $value) {
            return null;
        }

        if (array_key_exists('mysql', $value) && is_string($value['mysql']) && '' !== trim($value['mysql'])) {
            return $value['mysql'];
        }

        if (array_key_exists('default', $value) && is_string($value['default']) && '' !== trim($value['default'])) {
            return $value['default'];
        }

        return null;
    }

    private function assertIdentifierLength(string $kind, string $name, ?string $table = null): void
    {
        if ('' === trim($name)) {
            return;
        }

        if (strlen($name) <= self::IDENTIFIER_LIMIT) {
            return;
        }

        $context = null === $table ? '' : sprintf(' on table "%s"', $table);
        throw new RuntimeException(sprintf(
            'MySQL %s name "%s"%s exceeds the %d-character identifier limit.',
            $kind,
            $name,
            $context,
            self::IDENTIFIER_LIMIT,
        ));
    }
}
