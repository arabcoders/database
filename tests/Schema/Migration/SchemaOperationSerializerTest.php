<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaOperationSerializer;
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
use PHPUnit\Framework\TestCase;

final class SchemaOperationSerializerTest extends TestCase
{
    public function testOperationSerializationRoundTrip(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            'id',
            ColumnType::Int,
            autoIncrement: true,
            check: true,
            checkExpression: 'id > 0',
        ));
        $table->setPrimaryKey(['id']);
        $table->addIndex(new IndexDefinition('idx_widgets_id', ['id'], where: 'id > 0'));
        $table->addIndex(new IndexDefinition('idx_widgets_expr', [], expression: '(lower(name))'));
        $table->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['id'], 'users', ['id']));

        $fromTable = new TableDefinition('widgets_old');
        $fromTable->addColumn(new ColumnDefinition('legacy', ColumnType::Text));

        $column = new ColumnDefinition(
            'name',
            ColumnType::VarChar,
            length: 255,
            generated: true,
            generatedExpression: 'lower(name)',
            generatedStored: true,
        );
        $operations = [
            new CreateTableOperation($table),
            new DropTableOperation($table),
            new AddColumnOperation('widgets', $column),
            new DropColumnOperation('widgets', $column),
            new AlterColumnOperation('widgets', $column, new ColumnDefinition('name', ColumnType::VarChar, length: 100)),
            new AddIndexOperation('widgets', new IndexDefinition('idx_widgets_name', ['name'])),
            new DropIndexOperation('widgets', new IndexDefinition('idx_widgets_name', ['name'])),
            new AddForeignKeyOperation('widgets', new ForeignKeyDefinition('fk_widgets_user', ['id'], 'users', ['id'])),
            new DropForeignKeyOperation('widgets', new ForeignKeyDefinition('fk_widgets_user', ['id'], 'users', ['id'])),
            new AddPrimaryKeyOperation('widgets', ['id']),
            new DropPrimaryKeyOperation('widgets', ['id']),
            new RenameTableOperation('widgets_old', 'widgets'),
            new RenameColumnOperation('widgets', 'old_name', 'new_name'),
            new RebuildTableOperation($fromTable, $table),
        ];

        $payload = SchemaOperationSerializer::toArray($operations);
        $restored = SchemaOperationSerializer::fromArray($payload);

        static::assertCount(count($operations), $restored);
        static::assertInstanceOf(CreateTableOperation::class, $restored[0]);
        static::assertInstanceOf(DropTableOperation::class, $restored[1]);
        static::assertInstanceOf(AddColumnOperation::class, $restored[2]);
        static::assertInstanceOf(DropColumnOperation::class, $restored[3]);
        static::assertInstanceOf(AlterColumnOperation::class, $restored[4]);
        static::assertInstanceOf(AddIndexOperation::class, $restored[5]);
        static::assertInstanceOf(DropIndexOperation::class, $restored[6]);
        static::assertInstanceOf(AddForeignKeyOperation::class, $restored[7]);
        static::assertInstanceOf(DropForeignKeyOperation::class, $restored[8]);
        static::assertInstanceOf(AddPrimaryKeyOperation::class, $restored[9]);
        static::assertInstanceOf(DropPrimaryKeyOperation::class, $restored[10]);
        static::assertInstanceOf(RenameTableOperation::class, $restored[11]);
        static::assertInstanceOf(RenameColumnOperation::class, $restored[12]);
        static::assertInstanceOf(RebuildTableOperation::class, $restored[13]);

        /** @var CreateTableOperation $create */
        $create = $restored[0];
        $restoredExprIndex = $create->table->getIndex('idx_widgets_expr');
        static::assertNotNull($restoredExprIndex);
        static::assertSame('(lower(name))', $restoredExprIndex->expression);

        /** @var AddColumnOperation $addColumn */
        $addColumn = $restored[2];
        static::assertTrue($addColumn->column->generated);
        static::assertSame('lower(name)', $addColumn->column->generatedExpression);
        static::assertTrue($addColumn->column->generatedStored);
    }
}
