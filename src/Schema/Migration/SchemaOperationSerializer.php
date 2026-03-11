<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

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
use RuntimeException;

final class SchemaOperationSerializer
{
    /**
     * @param array<int,SchemaOperation> $operations
     * @return array<int,array<string,mixed>>
     */
    public static function toArray(array $operations): array
    {
        return array_map(self::operationToArray(...), $operations);
    }

    /**
     * @param array<int,array<string,mixed>> $payload
     * @return array<int,SchemaOperation>
     */
    public static function fromArray(array $payload): array
    {
        $operations = [];
        foreach ($payload as $operationData) {
            $operations[] = self::operationFromArray($operationData);
        }

        return $operations;
    }

    /**
     * @return array<string,mixed>
     */
    public static function operationToArray(SchemaOperation $operation): array
    {
        if ($operation instanceof CreateTableOperation) {
            return [
                'type' => CreateTableOperation::TYPE,
                'table' => SchemaDefinitionSerializer::tableToArray($operation->table),
            ];
        }

        if ($operation instanceof DropTableOperation) {
            return [
                'type' => DropTableOperation::TYPE,
                'table' => SchemaDefinitionSerializer::tableToArray($operation->table),
            ];
        }

        if ($operation instanceof AddColumnOperation) {
            return [
                'type' => AddColumnOperation::TYPE,
                'table' => $operation->table,
                'column' => SchemaDefinitionSerializer::columnToArray($operation->column),
            ];
        }

        if ($operation instanceof DropColumnOperation) {
            return [
                'type' => DropColumnOperation::TYPE,
                'table' => $operation->table,
                'column' => SchemaDefinitionSerializer::columnToArray($operation->column),
            ];
        }

        if ($operation instanceof AlterColumnOperation) {
            return [
                'type' => AlterColumnOperation::TYPE,
                'table' => $operation->table,
                'from' => SchemaDefinitionSerializer::columnToArray($operation->from),
                'to' => SchemaDefinitionSerializer::columnToArray($operation->to),
            ];
        }

        if ($operation instanceof AddIndexOperation) {
            return [
                'type' => AddIndexOperation::TYPE,
                'table' => $operation->table,
                'index' => SchemaDefinitionSerializer::indexToArray($operation->index),
            ];
        }

        if ($operation instanceof DropIndexOperation) {
            return [
                'type' => DropIndexOperation::TYPE,
                'table' => $operation->table,
                'index' => SchemaDefinitionSerializer::indexToArray($operation->index),
            ];
        }

        if ($operation instanceof AddForeignKeyOperation) {
            return [
                'type' => AddForeignKeyOperation::TYPE,
                'table' => $operation->table,
                'foreignKey' => SchemaDefinitionSerializer::foreignKeyToArray($operation->foreignKey),
            ];
        }

        if ($operation instanceof DropForeignKeyOperation) {
            return [
                'type' => DropForeignKeyOperation::TYPE,
                'table' => $operation->table,
                'foreignKey' => SchemaDefinitionSerializer::foreignKeyToArray($operation->foreignKey),
            ];
        }

        if ($operation instanceof AddPrimaryKeyOperation) {
            return [
                'type' => AddPrimaryKeyOperation::TYPE,
                'table' => $operation->table,
                'columns' => $operation->columns,
            ];
        }

        if ($operation instanceof DropPrimaryKeyOperation) {
            return [
                'type' => DropPrimaryKeyOperation::TYPE,
                'table' => $operation->table,
                'columns' => $operation->columns,
            ];
        }

        if ($operation instanceof RenameTableOperation) {
            return [
                'type' => RenameTableOperation::TYPE,
                'from' => $operation->from,
                'to' => $operation->to,
            ];
        }

        if ($operation instanceof RenameColumnOperation) {
            return [
                'type' => RenameColumnOperation::TYPE,
                'table' => $operation->table,
                'from' => $operation->from,
                'to' => $operation->to,
            ];
        }

        if ($operation instanceof RebuildTableOperation) {
            return [
                'type' => RebuildTableOperation::TYPE,
                'from' => SchemaDefinitionSerializer::tableToArray($operation->from),
                'to' => SchemaDefinitionSerializer::tableToArray($operation->to),
            ];
        }

        throw new RuntimeException('Unsupported schema operation.');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function operationFromArray(array $payload): SchemaOperation
    {
        $type = (string) ($payload['type'] ?? '');

        return match ($type) {
            CreateTableOperation::TYPE => new CreateTableOperation(
                SchemaDefinitionSerializer::tableFromArray(self::requireArray($payload, 'table')),
            ),
            DropTableOperation::TYPE => new DropTableOperation(
                SchemaDefinitionSerializer::tableFromArray(self::requireArray($payload, 'table')),
            ),
            AddColumnOperation::TYPE => new AddColumnOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::columnFromArray(self::requireArray($payload, 'column')),
            ),
            DropColumnOperation::TYPE => new DropColumnOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::columnFromArray(self::requireArray($payload, 'column')),
            ),
            AlterColumnOperation::TYPE => new AlterColumnOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::columnFromArray(self::requireArray($payload, 'from')),
                SchemaDefinitionSerializer::columnFromArray(self::requireArray($payload, 'to')),
            ),
            AddIndexOperation::TYPE => new AddIndexOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::indexFromArray(self::requireArray($payload, 'index')),
            ),
            DropIndexOperation::TYPE => new DropIndexOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::indexFromArray(self::requireArray($payload, 'index')),
            ),
            AddForeignKeyOperation::TYPE => new AddForeignKeyOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::foreignKeyFromArray(self::requireArray($payload, 'foreignKey')),
            ),
            DropForeignKeyOperation::TYPE => new DropForeignKeyOperation(
                (string) ($payload['table'] ?? ''),
                SchemaDefinitionSerializer::foreignKeyFromArray(self::requireArray($payload, 'foreignKey')),
            ),
            AddPrimaryKeyOperation::TYPE => new AddPrimaryKeyOperation(
                (string) ($payload['table'] ?? ''),
                $payload['columns'] ?? [],
            ),
            DropPrimaryKeyOperation::TYPE => new DropPrimaryKeyOperation(
                (string) ($payload['table'] ?? ''),
                $payload['columns'] ?? [],
            ),
            RenameTableOperation::TYPE => new RenameTableOperation(
                (string) ($payload['from'] ?? ''),
                (string) ($payload['to'] ?? ''),
            ),
            RenameColumnOperation::TYPE => new RenameColumnOperation(
                (string) ($payload['table'] ?? ''),
                (string) ($payload['from'] ?? ''),
                (string) ($payload['to'] ?? ''),
            ),
            RebuildTableOperation::TYPE => new RebuildTableOperation(
                SchemaDefinitionSerializer::tableFromArray(self::requireArray($payload, 'from')),
                SchemaDefinitionSerializer::tableFromArray(self::requireArray($payload, 'to')),
            ),
            default => throw new RuntimeException('Unsupported schema operation type.'),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private static function requireArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('Invalid schema operation payload.');
        }

        return $value;
    }
}
