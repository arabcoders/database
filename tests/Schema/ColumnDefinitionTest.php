<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use PHPUnit\Framework\TestCase;

final class ColumnDefinitionTest extends TestCase
{
    public function testEqualsHandlesDefaultsAndFlags(): void
    {
        $columnA = new ColumnDefinition(
            name: 'total',
            type: ColumnType::Int,
            length: 11,
            unsigned: true,
            nullable: false,
            autoIncrement: false,
            hasDefault: true,
            default: 1,
            defaultIsExpression: false,
            comment: 'Total',
            onUpdate: null,
        );

        $columnB = new ColumnDefinition(
            name: 'total',
            type: ColumnType::Int,
            length: 11,
            unsigned: true,
            nullable: false,
            autoIncrement: false,
            hasDefault: true,
            default: '1',
            defaultIsExpression: false,
            comment: 'Total',
            onUpdate: null,
        );

        static::assertTrue($columnA->equals($columnB));

        $columnC = new ColumnDefinition(
            name: 'total',
            type: ColumnType::Int,
            length: 11,
            unsigned: true,
            nullable: false,
            autoIncrement: false,
            hasDefault: true,
            default: '1',
            defaultIsExpression: true,
            comment: 'Total',
            onUpdate: null,
        );

        static::assertFalse($columnA->equals($columnC));
    }

    public function testEqualsDetectsTypeChanges(): void
    {
        $columnA = new ColumnDefinition('name', ColumnType::VarChar, length: 255);
        $columnB = new ColumnDefinition('name', ColumnType::Text);

        static::assertFalse($columnA->equals($columnB));
    }

    public function testEqualsDetectsMetadataChanges(): void
    {
        $columnA = new ColumnDefinition(
            name: 'name',
            type: ColumnType::VarChar,
            length: 255,
            nullable: true,
            charset: ['default' => 'utf8mb4'],
            collation: ['default' => 'utf8mb4_unicode_ci'],
            comment: 'Name',
            onUpdate: 'CURRENT_TIMESTAMP',
        );

        $columnB = new ColumnDefinition(
            name: 'name',
            type: ColumnType::VarChar,
            length: 255,
            nullable: true,
            charset: ['default' => 'utf8'],
            collation: ['default' => 'utf8mb4_unicode_ci'],
            comment: 'Name',
            onUpdate: 'CURRENT_TIMESTAMP',
        );

        static::assertFalse($columnA->equals($columnB));

        $columnC = new ColumnDefinition(
            name: 'name',
            type: ColumnType::VarChar,
            length: 255,
            nullable: true,
            charset: ['default' => 'utf8mb4'],
            collation: ['default' => 'utf8mb4_unicode_ci'],
            comment: 'Other',
            onUpdate: 'CURRENT_TIMESTAMP',
        );

        static::assertFalse($columnA->equals($columnC));
    }

    public function testEqualsNormalizesNullAndBooleanDefaults(): void
    {
        $columnA = new ColumnDefinition(
            name: 'flag',
            type: ColumnType::TinyInt,
            length: 1,
            hasDefault: true,
            default: true,
        );

        $columnB = new ColumnDefinition(
            name: 'flag',
            type: ColumnType::TinyInt,
            length: 1,
            hasDefault: true,
            default: 1,
        );

        static::assertTrue($columnA->equals($columnB));

        $columnC = new ColumnDefinition(
            name: 'flag',
            type: ColumnType::TinyInt,
            length: 1,
            hasDefault: true,
            default: null,
        );

        static::assertFalse($columnA->equals($columnC));
    }

    public function testEqualsNormalizesArrayDefaults(): void
    {
        $columnA = new ColumnDefinition(
            name: 'meta',
            type: ColumnType::Json,
            hasDefault: true,
            default: ['a' => 1],
        );

        $columnB = new ColumnDefinition(
            name: 'meta',
            type: ColumnType::Json,
            hasDefault: true,
            default: ['a' => 1],
        );

        static::assertTrue($columnA->equals($columnB));
    }

    public function testEqualsComparesCustomTypeNames(): void
    {
        $columnA = new ColumnDefinition(
            name: 'payload',
            type: ColumnType::Custom,
            typeName: 'jsonb',
        );

        $columnB = new ColumnDefinition(
            name: 'payload',
            type: ColumnType::Custom,
            typeName: 'json',
        );

        static::assertFalse($columnA->equals($columnB));
    }

    public function testEqualsComparesGeneratedMetadata(): void
    {
        $columnA = new ColumnDefinition(
            name: 'computed',
            type: ColumnType::Int,
            generated: true,
            generatedExpression: 'base + 1',
            generatedStored: true,
        );

        $columnB = new ColumnDefinition(
            name: 'computed',
            type: ColumnType::Int,
            generated: true,
            generatedExpression: 'base + 2',
            generatedStored: true,
        );

        static::assertFalse($columnA->equals($columnB));
    }
}
