<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigrationExporter;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use PHPUnit\Framework\TestCase;

final class SchemaBlueprintMigrationExporterTest extends TestCase
{
    public function testExporterOutputsBlueprintTemplate(): void
    {
        $from = new SchemaDefinition();
        $to = new SchemaDefinition();
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            'id',
            ColumnType::Int,
            autoIncrement: true,
            check: true,
            checkExpression: 'id > 0',
        ));
        $table->addColumn(new ColumnDefinition(
            'name',
            ColumnType::VarChar,
            length: 255,
            generated: true,
            generatedExpression: 'lower(name)',
            generatedStored: true,
        ));
        $table->addIndex(new IndexDefinition('idx_widgets_expr', [], expression: '(lower(name))'));
        $table->setPrimaryKey(['id']);
        $to->addTable($table);

        $plan = new SchemaMigrationPlan($from, $to, [new CreateTableOperation($table)]);
        $content = new SchemaBlueprintMigrationExporter()->export($plan, 'Migration_1_widgets', '1', 'widgets');

        static::assertStringContainsString('SchemaBlueprintMigration', $content);
        static::assertStringContainsString('__invoke', $content);
        static::assertStringContainsString('Migration(id: ', $content);
        static::assertStringContainsString('ColumnType::Int', $content);
        static::assertStringContainsString('->check(', $content);
        static::assertStringContainsString('->generated(', $content);
        static::assertStringContainsString('expression: ', $content);
    }
}
