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
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use tests\TestCase;

final class SchemaBlueprintMigrationExporterTest extends TestCase
{
    public function testExporterRendersBlueprintTemplate(): void
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
        static::assertStringContainsString('useMigrationPlan', $content);
    }

    public function testExporterKeepsOnlyRelevantPlanTables(): void
    {
        $from = new SchemaDefinition();
        $legacy = new TableDefinition('legacy_widgets');
        $legacy->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $legacy->setPrimaryKey(['id']);
        $from->addTable($legacy);

        $audit = new TableDefinition('audit_logs');
        $audit->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $audit->setPrimaryKey(['id']);
        $from->addTable($audit);

        $to = new SchemaDefinition();
        $widgets = new TableDefinition('widgets', previousName: 'legacy_widgets');
        $widgets->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $widgets->addColumn(new ColumnDefinition('name', ColumnType::VarChar, length: 255));
        $widgets->setPrimaryKey(['id']);
        $to->addTable($widgets);

        $users = new TableDefinition('users');
        $users->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $users->setPrimaryKey(['id']);
        $to->addTable($users);

        $plan = new SchemaMigrationPlan($from, $to, [
            new RenameTableOperation('legacy_widgets', 'widgets'),
            new AddColumnOperation('widgets', new ColumnDefinition('name', ColumnType::VarChar, length: 255)),
        ]);

        $content = new SchemaBlueprintMigrationExporter()->export($plan, 'Migration_3_widgets', '3', 'widgets');

        static::assertStringContainsString("'legacy_widgets'", $content);
        static::assertStringContainsString("'widgets'", $content);
        static::assertStringNotContainsString("'audit_logs'", $content);
        static::assertStringNotContainsString("'users'", $content);
    }

    public function testExporterKeepsDroppedIndexMetadata(): void
    {
        $from = new SchemaDefinition();
        $to = new SchemaDefinition();

        $plan = new SchemaMigrationPlan($from, $to, [
            new DropIndexOperation('widgets', new IndexDefinition(
                'idx_widgets_name',
                ['name'],
                unique: true,
                algorithm: ['pgsql' => 'hash'],
                where: 'name IS NOT NULL',
            )),
            new DropIndexOperation('widgets', new IndexDefinition(
                'idx_widgets_expr',
                [],
                expression: '(lower(name))',
            )),
        ]);

        $content = new SchemaBlueprintMigrationExporter()->export($plan, 'Migration_2_widgets', '2', 'widgets');

        static::assertStringContainsString(
            "\$table->dropIndex('idx_widgets_name', columns: 'name', unique: true, algorithm: ['pgsql' => 'hash'], where: 'name IS NOT NULL');",
            $content,
        );
        static::assertStringContainsString(
            "\$table->dropIndex('idx_widgets_expr', columns: [], expression: '(lower(name))');",
            $content,
        );
    }
}
