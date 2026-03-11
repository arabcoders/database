<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'hooked_columns')]
final class OnCreateUpdateEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 36, hooks: ['create' => 'tests\\fixtures\\OnCreateUpdateEntity::makeUuid'])]
    public string $uuid = '';

    #[Column(type: ColumnType::DateTime, hooks: ['create' => 'tests\\fixtures\\OnCreateUpdateEntity::stampTime'], name: 'created_at')]
    public string $createdAt = '';

    #[Column(type: ColumnType::DateTime, hooks: ['update' => 'tests\\fixtures\\OnCreateUpdateEntity::stampTime'], name: 'updated_at')]
    public string $updatedAt = '';

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    public static function makeUuid(): string
    {
        return 'uuid-1234';
    }

    public static function stampTime(object $entity, string $property, string $event): string
    {
        return 'create' === $event ? '2024-01-01 00:00:00' : '2024-01-02 00:00:00';
    }
}
