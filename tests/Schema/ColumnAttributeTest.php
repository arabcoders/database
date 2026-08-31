<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Schema\Definition\ColumnType;
use tests\TestCase;

final class ColumnAttributeTest extends TestCase
{
    public function testNameAssignsExplicit(): void
    {
        $column = new Column(type: ColumnType::VarChar, name: 'db_column');
        static::assertSame('db_column', $column->name);
    }

    public function testNameDefaultsProperty(): void
    {
        $column = new Column(type: ColumnType::VarChar);
        static::assertNull($column->name);
    }

    public function testPrevNameValue(): void
    {
        $column = new Column(type: ColumnType::VarChar, prevName: 'legacy_name');
        static::assertSame('legacy_name', $column->prevName);
    }

    public function testTypeNameValue(): void
    {
        $column = new Column(type: ColumnType::Custom, typeName: 'jsonb');
        static::assertSame('jsonb', $column->typeName);
    }

    public function testAdvancedColumnMetadata(): void
    {
        $column = new Column(
            type: ColumnType::Int,
            allowed: ['1', '2'],
            check: true,
            checkExpression: 'value > 0',
            generated: true,
            generatedExpression: 'base + 1',
            generatedStored: true,
        );

        static::assertSame(['1', '2'], $column->allowed);
        static::assertTrue($column->check);
        static::assertSame('value > 0', $column->checkExpression);
        static::assertTrue($column->generated);
        static::assertSame('base + 1', $column->generatedExpression);
        static::assertTrue($column->generatedStored);
    }
}
