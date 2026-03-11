<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use PHPUnit\Framework\TestCase;

final class SchemaMigrationPlanTest extends TestCase
{
    public function testMigrationPlanSerializesAndRestores(): void
    {
        $from = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $fromTable->setPrimaryKey(['id']);
        $from->addTable($fromTable);

        $to = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition(
            'id',
            ColumnType::Int,
            autoIncrement: true,
            check: true,
            checkExpression: 'id > 0',
        ));
        $toTable->addColumn(new ColumnDefinition(
            'name',
            ColumnType::VarChar,
            length: 100,
            generated: true,
            generatedExpression: 'lower(name)',
            generatedStored: true,
        ));
        $toTable->addIndex(new IndexDefinition('idx_widgets_name', ['name'], where: 'name IS NOT NULL'));
        $toTable->addIndex(new IndexDefinition('idx_widgets_lower_name', [], expression: '(lower(name))'));
        $toTable->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['id'], 'users', ['id']));
        $toTable->setPrimaryKey(['id']);
        $to->addTable($toTable);

        $operations = [
            new AddColumnOperation('widgets', new ColumnDefinition('name', ColumnType::VarChar, length: 100)),
            new RenameTableOperation('legacy_widgets', 'widgets'),
        ];

        $plan = new SchemaMigrationPlan($from, $to, $operations);
        $payload = $plan->toArray();
        $restored = SchemaMigrationPlan::fromArray($payload);

        static::assertSame(['widgets'], array_keys($restored->from->getTables()));
        static::assertSame(['widgets'], array_keys($restored->to->getTables()));
        static::assertCount(2, $restored->operations);
        static::assertInstanceOf(AddColumnOperation::class, $restored->operations[0]);
        static::assertInstanceOf(RenameTableOperation::class, $restored->operations[1]);

        $restoredToTable = $restored->to->getTable('widgets');
        static::assertNotNull($restoredToTable);

        $restoredId = $restoredToTable->getColumn('id');
        static::assertNotNull($restoredId);
        static::assertTrue($restoredId->check);
        static::assertSame('id > 0', $restoredId->checkExpression);

        $restoredName = $restoredToTable->getColumn('name');
        static::assertNotNull($restoredName);
        static::assertTrue($restoredName->generated);
        static::assertSame('lower(name)', $restoredName->generatedExpression);
        static::assertTrue($restoredName->generatedStored);

        $restoredWhereIndex = $restoredToTable->getIndex('idx_widgets_name');
        static::assertNotNull($restoredWhereIndex);
        static::assertSame('name IS NOT NULL', $restoredWhereIndex->where);

        $restoredExpressionIndex = $restoredToTable->getIndex('idx_widgets_lower_name');
        static::assertNotNull($restoredExpressionIndex);
        static::assertSame('(lower(name))', $restoredExpressionIndex->expression);
        static::assertSame([], $restoredExpressionIndex->columns);
    }
}
