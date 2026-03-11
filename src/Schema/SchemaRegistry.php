<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\ForeignKey;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Scanner\Attributes as AttributesScanner;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Utils\NameHelper;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class SchemaRegistry
{
    public function __construct(
        private array $dirs,
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    /**
     * Scan model classes and build an in-memory schema definition from attributes.
     *
     * @return SchemaDefinition
     * @throws RuntimeException If an index or foreign key attribute is missing required columns.
     */
    public function build(): SchemaDefinition
    {
        $schema = new SchemaDefinition();
        $scanner = AttributesScanner::scan($this->dirs, true, $this->container);

        foreach ($scanner->for(Table::class) as $item) {
            $callable = $item->getCallable();
            if (!is_string($callable)) {
                continue;
            }

            $reflection = new ReflectionClass($callable);
            $tableAttribute = $this->resolveTableAttribute($reflection);
            if (null === $tableAttribute) {
                continue;
            }

            $tableName = $this->resolveTableName($reflection, $tableAttribute);
            $table = new TableDefinition(
                name: $tableName,
                engine: $tableAttribute->engine,
                charset: $tableAttribute->charset,
                collation: $tableAttribute->collation,
                previousName: $tableAttribute->prevName,
                sourceClass: $reflection->getName(),
            );

            $columnMap = [];
            $primaryColumns = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $columnAttribute = $this->resolveColumnAttribute($property);
                if (null === $columnAttribute) {
                    continue;
                }

                $columnName = $this->resolveColumnName($property->getName(), $columnAttribute);
                $columnMap[$property->getName()] = $columnName;

                [$charset, $collation] = $this->resolveColumnCharset(
                    $columnAttribute->type,
                    $columnAttribute->charset,
                    $columnAttribute->collation,
                    $table,
                );

                $column = new ColumnDefinition(
                    name: $columnName,
                    type: $columnAttribute->type,
                    length: $columnAttribute->length,
                    precision: $columnAttribute->precision,
                    scale: $columnAttribute->scale,
                    unsigned: $columnAttribute->unsigned,
                    nullable: $columnAttribute->nullable,
                    autoIncrement: $columnAttribute->autoIncrement,
                    hasDefault: $columnAttribute->hasDefault,
                    default: $columnAttribute->default,
                    defaultIsExpression: $columnAttribute->defaultIsExpression,
                    charset: $charset,
                    collation: $collation,
                    comment: $columnAttribute->comment,
                    onUpdate: $columnAttribute->onUpdate,
                    previousName: $columnAttribute->prevName,
                    propertyName: $property->getName(),
                    typeName: $columnAttribute->typeName,
                    allowed: $columnAttribute->allowed,
                    check: $columnAttribute->check,
                    checkExpression: $columnAttribute->checkExpression,
                    generated: $columnAttribute->generated,
                    generatedExpression: $columnAttribute->generatedExpression,
                    generatedStored: $columnAttribute->generatedStored,
                );

                $table->addColumn($column);

                if ($columnAttribute->primary) {
                    $primaryColumns[] = $columnName;
                }

                foreach ($property->getAttributes(Index::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                    $attribute = $attributeRef->newInstance();
                    $columns = $this->resolveColumns($attribute->columns, $columnMap, $columnName);
                    $hasExpression = null !== $attribute->expression && '' !== trim($attribute->expression);
                    if ($hasExpression) {
                        if (null === $attribute->name || '' === trim($attribute->name)) {
                            throw new RuntimeException('Expression index name is required.');
                        }

                        $columns = [];
                    }

                    $name = $attribute->name ?? NameHelper::indexName($tableName, $columns, false, $attribute->type);
                    $table->addIndex(new IndexDefinition(
                        name: $name,
                        columns: $columns,
                        unique: false,
                        type: $attribute->type,
                        algorithm: $attribute->algorithm,
                        where: $attribute->where,
                        expression: $attribute->expression,
                    ));
                }

                foreach ($property->getAttributes(Unique::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                    $attribute = $attributeRef->newInstance();
                    $columns = $this->resolveColumns($attribute->columns, $columnMap, $columnName);
                    $hasExpression = null !== $attribute->expression && '' !== trim($attribute->expression);
                    if ($hasExpression) {
                        if (null === $attribute->name || '' === trim($attribute->name)) {
                            throw new RuntimeException('Expression unique index name is required.');
                        }

                        $columns = [];
                    }

                    $name = $attribute->name ?? NameHelper::indexName($tableName, $columns, true, 'unique');
                    $table->addIndex(new IndexDefinition(
                        name: $name,
                        columns: $columns,
                        unique: true,
                        type: 'index',
                        algorithm: $attribute->algorithm,
                        where: $attribute->where,
                        expression: $attribute->expression,
                    ));
                }

                foreach ($property->getAttributes(ForeignKey::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                    $attribute = $attributeRef->newInstance();
                    $columns = $this->resolveColumns($attribute->columns, $columnMap, $columnName);
                    [$referencesTable, $referencesColumns] = $this->resolveForeignReference($attribute);
                    $name = $attribute->name ?? NameHelper::foreignKeyName($tableName, $columns, $referencesTable);
                    $table->addForeignKey(new ForeignKeyDefinition(
                        name: $name,
                        columns: $columns,
                        referencesTable: $referencesTable,
                        referencesColumns: $referencesColumns,
                        onDelete: $attribute->onDelete,
                        onUpdate: $attribute->onUpdate,
                    ));
                }
            }

            foreach ($reflection->getAttributes(Index::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                $attribute = $attributeRef->newInstance();
                $columns = $this->resolveColumns($attribute->columns, $columnMap);
                $hasExpression = null !== $attribute->expression && '' !== trim($attribute->expression);
                if ($hasExpression) {
                    $columns = [];
                }

                if (empty($columns) && !$hasExpression) {
                    throw new RuntimeException('Index columns are required.');
                }

                if ($hasExpression && (null === $attribute->name || '' === trim($attribute->name))) {
                    throw new RuntimeException('Expression index name is required.');
                }

                $name = $attribute->name ?? NameHelper::indexName($tableName, $columns, false, $attribute->type);
                $table->addIndex(new IndexDefinition(
                    name: $name,
                    columns: $columns,
                    unique: false,
                    type: $attribute->type,
                    algorithm: $attribute->algorithm,
                    where: $attribute->where,
                    expression: $attribute->expression,
                ));
            }

            foreach ($reflection->getAttributes(Unique::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                $attribute = $attributeRef->newInstance();
                $columns = $this->resolveColumns($attribute->columns, $columnMap);
                $hasExpression = null !== $attribute->expression && '' !== trim($attribute->expression);
                if ($hasExpression) {
                    $columns = [];
                }

                if (empty($columns) && !$hasExpression) {
                    throw new RuntimeException('Unique columns are required.');
                }

                if ($hasExpression && (null === $attribute->name || '' === trim($attribute->name))) {
                    throw new RuntimeException('Expression unique index name is required.');
                }

                $name = $attribute->name ?? NameHelper::indexName($tableName, $columns, true, 'unique');
                $table->addIndex(new IndexDefinition(
                    name: $name,
                    columns: $columns,
                    unique: true,
                    type: 'index',
                    algorithm: $attribute->algorithm,
                    where: $attribute->where,
                    expression: $attribute->expression,
                ));
            }

            foreach ($reflection->getAttributes(ForeignKey::class, ReflectionAttribute::IS_INSTANCEOF) as $attributeRef) {
                $attribute = $attributeRef->newInstance();
                $columns = $this->resolveColumns($attribute->columns, $columnMap);
                if (empty($columns)) {
                    throw new RuntimeException('ForeignKey columns are required.');
                }
                [$referencesTable, $referencesColumns] = $this->resolveForeignReference($attribute);
                $name = $attribute->name ?? NameHelper::foreignKeyName($tableName, $columns, $referencesTable);
                $table->addForeignKey(new ForeignKeyDefinition(
                    name: $name,
                    columns: $columns,
                    referencesTable: $referencesTable,
                    referencesColumns: $referencesColumns,
                    onDelete: $attribute->onDelete,
                    onUpdate: $attribute->onUpdate,
                ));
            }

            $tablePrimary = $this->resolveColumns($tableAttribute->primaryKey, $columnMap);
            if (!empty($tablePrimary)) {
                $table->setPrimaryKey($tablePrimary);
            } elseif (!empty($primaryColumns)) {
                $table->setPrimaryKey($primaryColumns);
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    private function resolveTableAttribute(ReflectionClass $class): ?Table
    {
        $attributes = $class->getAttributes(Table::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private function resolveColumnAttribute(ReflectionProperty $property): ?Column
    {
        $attributes = $property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private function resolveTableName(ReflectionClass $class, Table $attribute): string
    {
        return $attribute->name ?? $class->getShortName();
    }

    private function resolveColumnName(string $propertyName, Column $attribute): string
    {
        return $attribute->name ?? $propertyName;
    }

    /**
     * @return array{0:array,1:array}
     */
    private function resolveColumnCharset(
        ColumnType $type,
        array $charset,
        array $collation,
        TableDefinition $table,
    ): array {
        if (!$this->supportsColumnCharset($type)) {
            return [$charset, $collation];
        }

        if ([] === $charset) {
            $charset = $table->charset;
        }

        if ([] === $collation) {
            $collation = $table->collation;
        }

        return [$charset, $collation];
    }

    private function supportsColumnCharset(ColumnType $type): bool
    {
        return in_array(
            $type,
            [
                ColumnType::Char,
                ColumnType::VarChar,
                ColumnType::Text,
                ColumnType::MediumText,
                ColumnType::LongText,
            ],
            true,
        );
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,string> $columnMap
     * @return array<int,string>
     */
    private function resolveColumns(array $columns, array $columnMap, ?string $fallback = null): array
    {
        if (empty($columns) && null !== $fallback) {
            return [$fallback];
        }

        $resolved = [];
        foreach ($columns as $column) {
            $resolved[] = $columnMap[$column] ?? $column;
        }

        return $resolved;
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function resolveForeignReference(ForeignKey $attribute): array
    {
        $referencesModel = $this->resolveReferencesModel($attribute);
        $referencesTable = $this->resolveReferencesTable($attribute, $referencesModel);
        $referencesColumns = $this->resolveReferencesColumns($attribute, $referencesModel);

        return [$referencesTable, $referencesColumns];
    }

    private function resolveReferencesModel(ForeignKey $attribute): ?ReflectionClass
    {
        $referencesModel = $attribute->referencesModel;
        if (null === $referencesModel || '' === trim($referencesModel)) {
            return null;
        }

        if (!class_exists($referencesModel)) {
            throw new RuntimeException('ForeignKey referencesModel class not found: ' . $referencesModel);
        }

        return new ReflectionClass($referencesModel);
    }

    private function resolveReferencesTable(ForeignKey $attribute, ?ReflectionClass $referencesModel = null): string
    {
        if (is_string($attribute->referencesTable) && '' !== trim($attribute->referencesTable)) {
            return trim($attribute->referencesTable);
        }

        if (null === $referencesModel) {
            throw new RuntimeException('ForeignKey requires referencesTable or referencesModel.');
        }

        $table = $this->resolveTableAttribute($referencesModel);
        if (null === $table) {
            throw new RuntimeException('ForeignKey referencesModel must define a Table attribute: ' . $referencesModel->getName());
        }

        return $this->resolveTableName($referencesModel, $table);
    }

    /**
     * @return array<int,string>
     */
    private function resolveReferencesColumns(ForeignKey $attribute, ?ReflectionClass $referencesModel = null): array
    {
        if (!empty($attribute->referencesColumns)) {
            if (null === $referencesModel) {
                return $attribute->referencesColumns;
            }

            [$columnMap] = $this->resolveReferencedModelColumns($referencesModel);

            return $this->resolveColumns($attribute->referencesColumns, $columnMap);
        }

        if (null === $referencesModel) {
            throw new RuntimeException('ForeignKey referencesColumns is required.');
        }

        $table = $this->resolveTableAttribute($referencesModel);
        if (null === $table) {
            throw new RuntimeException('ForeignKey referencesModel must define a Table attribute: ' . $referencesModel->getName());
        }

        [$columnMap, $primaryColumns] = $this->resolveReferencedModelColumns($referencesModel);
        $tablePrimary = $this->resolveColumns($table->primaryKey, $columnMap);
        $resolved = !empty($tablePrimary) ? $tablePrimary : $primaryColumns;

        if (empty($resolved)) {
            throw new RuntimeException(
                'ForeignKey referencesModel must define a primary key to infer referencesColumns: ' . $referencesModel->getName(),
            );
        }

        return $resolved;
    }

    /**
     * @return array{0:array<string,string>,1:array<int,string>}
     */
    private function resolveReferencedModelColumns(ReflectionClass $class): array
    {
        $columnMap = [];
        $primaryColumns = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $columnAttribute = $this->resolveColumnAttribute($property);
            if (null === $columnAttribute) {
                continue;
            }

            $columnName = $this->resolveColumnName($property->getName(), $columnAttribute);
            $columnMap[$property->getName()] = $columnName;

            if ($columnAttribute->primary) {
                $primaryColumns[] = $columnName;
            }
        }

        return [$columnMap, $primaryColumns];
    }
}
