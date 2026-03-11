<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;

final class SchemaDefinitionSerializer
{
    /**
     * @return array<string,mixed>
     */
    public static function toArray(SchemaDefinition $schema): array
    {
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $tables[] = self::tableToArray($table);
        }

        return ['tables' => $tables];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): SchemaDefinition
    {
        $schema = new SchemaDefinition();
        $tables = $payload['tables'] ?? [];

        foreach ($tables as $tableData) {
            $schema->addTable(self::tableFromArray($tableData));
        }

        return $schema;
    }

    /**
     * @return array<string,mixed>
     */
    public static function tableToArray(TableDefinition $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[] = self::columnToArray($column);
        }

        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[] = self::indexToArray($index);
        }

        $foreignKeys = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = self::foreignKeyToArray($foreignKey);
        }

        return [
            'name' => $table->name,
            'engine' => $table->engine,
            'charset' => $table->charset,
            'collation' => $table->collation,
            'previousName' => $table->previousName,
            'sourceClass' => $table->sourceClass,
            'primaryKey' => $table->getPrimaryKey(),
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function tableFromArray(array $payload): TableDefinition
    {
        $table = new TableDefinition(
            name: (string) ($payload['name'] ?? ''),
            engine: $payload['engine'] ?? null,
            charset: $payload['charset'] ?? null,
            collation: $payload['collation'] ?? null,
            previousName: $payload['previousName'] ?? null,
            sourceClass: $payload['sourceClass'] ?? null,
        );

        foreach ($payload['columns'] ?? [] as $columnData) {
            $table->addColumn(self::columnFromArray($columnData));
        }

        foreach ($payload['indexes'] ?? [] as $indexData) {
            $table->addIndex(self::indexFromArray($indexData));
        }

        foreach ($payload['foreignKeys'] ?? [] as $foreignKeyData) {
            $table->addForeignKey(self::foreignKeyFromArray($foreignKeyData));
        }

        $primaryKey = $payload['primaryKey'] ?? [];
        $table->setPrimaryKey(is_array($primaryKey) ? $primaryKey : []);

        return $table;
    }

    /**
     * @return array<string,mixed>
     */
    public static function columnToArray(ColumnDefinition $column): array
    {
        return [
            'name' => $column->name,
            'type' => $column->type->value,
            'typeName' => $column->typeName,
            'length' => $column->length,
            'precision' => $column->precision,
            'scale' => $column->scale,
            'unsigned' => $column->unsigned,
            'nullable' => $column->nullable,
            'autoIncrement' => $column->autoIncrement,
            'hasDefault' => $column->hasDefault,
            'default' => $column->default,
            'defaultIsExpression' => $column->defaultIsExpression,
            'charset' => $column->charset,
            'collation' => $column->collation,
            'comment' => $column->comment,
            'onUpdate' => $column->onUpdate,
            'previousName' => $column->previousName,
            'propertyName' => $column->propertyName,
            'allowed' => $column->allowed,
            'check' => $column->check,
            'checkExpression' => $column->checkExpression,
            'generated' => $column->generated,
            'generatedExpression' => $column->generatedExpression,
            'generatedStored' => $column->generatedStored,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function columnFromArray(array $payload): ColumnDefinition
    {
        $typeValue = (string) ($payload['type'] ?? ColumnType::Text->value);
        $type = ColumnType::from($typeValue);
        $generatedStored = null;
        if (array_key_exists('generatedStored', $payload)) {
            $generatedStored = null === $payload['generatedStored'] ? null : (bool) $payload['generatedStored'];
        }

        return new ColumnDefinition(
            name: (string) ($payload['name'] ?? ''),
            type: $type,
            length: $payload['length'] ?? null,
            precision: $payload['precision'] ?? null,
            scale: $payload['scale'] ?? null,
            unsigned: (bool) ($payload['unsigned'] ?? false),
            nullable: (bool) ($payload['nullable'] ?? false),
            autoIncrement: (bool) ($payload['autoIncrement'] ?? false),
            hasDefault: (bool) ($payload['hasDefault'] ?? false),
            default: $payload['default'] ?? null,
            defaultIsExpression: (bool) ($payload['defaultIsExpression'] ?? false),
            charset: $payload['charset'] ?? null,
            collation: $payload['collation'] ?? null,
            comment: $payload['comment'] ?? null,
            onUpdate: $payload['onUpdate'] ?? null,
            previousName: $payload['previousName'] ?? null,
            propertyName: $payload['propertyName'] ?? null,
            typeName: $payload['typeName'] ?? null,
            allowed: $payload['allowed'] ?? null,
            check: (bool) ($payload['check'] ?? false),
            checkExpression: $payload['checkExpression'] ?? null,
            generated: (bool) ($payload['generated'] ?? false),
            generatedExpression: $payload['generatedExpression'] ?? null,
            generatedStored: $generatedStored,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function indexToArray(IndexDefinition $index): array
    {
        return [
            'name' => $index->name,
            'columns' => $index->columns,
            'unique' => $index->unique,
            'type' => $index->type,
            'algorithm' => $index->algorithm,
            'where' => $index->where,
            'expression' => $index->expression,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function indexFromArray(array $payload): IndexDefinition
    {
        return new IndexDefinition(
            name: (string) ($payload['name'] ?? ''),
            columns: $payload['columns'] ?? [],
            unique: (bool) ($payload['unique'] ?? false),
            type: (string) ($payload['type'] ?? 'index'),
            algorithm: $payload['algorithm'] ?? [],
            where: $payload['where'] ?? null,
            expression: $payload['expression'] ?? null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function foreignKeyToArray(ForeignKeyDefinition $foreignKey): array
    {
        return [
            'name' => $foreignKey->name,
            'columns' => $foreignKey->columns,
            'referencesTable' => $foreignKey->referencesTable,
            'referencesColumns' => $foreignKey->referencesColumns,
            'onDelete' => $foreignKey->onDelete,
            'onUpdate' => $foreignKey->onUpdate,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function foreignKeyFromArray(array $payload): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition(
            name: (string) ($payload['name'] ?? ''),
            columns: $payload['columns'] ?? [],
            referencesTable: (string) ($payload['referencesTable'] ?? ''),
            referencesColumns: $payload['referencesColumns'] ?? [],
            onDelete: $payload['onDelete'] ?? null,
            onUpdate: $payload['onUpdate'] ?? null,
        );
    }
}
