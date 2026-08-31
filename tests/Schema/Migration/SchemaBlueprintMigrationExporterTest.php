<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigrationExporter;
use arabcoders\database\Schema\Migration\SchemaBlueprintRunner;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use tests\Support\DatabaseTestCase;

final class SchemaBlueprintMigrationExporterTest extends DatabaseTestCase
{
    public function testExporterRendersBlueprint(): void
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

        $file = $this->tempDir('exported-migration') . '/Migration_1_widgets.php';
        file_put_contents($file, $content);
        require_once $file;

        $pdo = $this->memoryPdo();
        new SchemaBlueprintRunner($pdo)->run(new \Migration\Db\Migration_1_widgets(), 'up');

        $schema = new \arabcoders\database\Schema\SchemaIntrospector($pdo)->introspect();
        $widgets = $schema->getTable('widgets');
        static::assertNotNull($widgets);
        static::assertSame(['id', 'name'], array_keys($widgets->getColumns()));
        static::assertSame(['id'], $widgets->getPrimaryKey());
        static::assertSame('(lower(name))', $widgets->getIndex('idx_widgets_expr')?->expression);
        static::assertSame('id > 0', $widgets->getColumn('id')?->checkExpression);
        static::assertSame('lower(name)', $widgets->getColumn('name')?->generatedExpression);
    }

    public function testKeepsPlanTables(): void
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

        $file = $this->tempDir('exported-rename') . '/Migration_3_widgets.php';
        file_put_contents($file, $content);
        require_once $file;

        $pdo = $this->memoryPdo();
        $pdo->exec('CREATE TABLE legacy_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        new SchemaBlueprintRunner($pdo)->run(new \Migration\Db\Migration_3_widgets(), 'up');

        $blueprint = new Blueprint($from);
        (new \Migration\Db\Migration_3_widgets())(
            new Connection($pdo, DialectFactory::fromPdo($pdo)),
            $blueprint,
        );

        static::assertCount(2, $blueprint->getOperations());
        static::assertSame('legacy_widgets', $blueprint->getOperations()[0]->from);
        static::assertSame('widgets', $blueprint->getOperations()[0]->to);
        static::assertSame('name', $blueprint->getOperations()[1]->column->name);
    }

    public function testKeepsDroppedIndexes(): void
    {
        $from = new SchemaDefinition();
        $to = new SchemaDefinition();

        $plan = new SchemaMigrationPlan($from, $to, [
            new DropIndexOperation('widgets', new IndexDefinition(
                'idx_widgets_name',
                ['name'],
                unique: true,
                algorithm: ['pgsql' => 'hash'],
                lengths: ['name' => 191],
                where: 'name IS NOT NULL',
            )),
            new DropIndexOperation('widgets', new IndexDefinition(
                'idx_widgets_expr',
                [],
                expression: '(lower(name))',
            )),
        ]);

        $content = new SchemaBlueprintMigrationExporter()->export($plan, 'Migration_2_widgets', '2', 'widgets');

        $file = $this->tempDir('exported-index-drop') . '/Migration_2_widgets.php';
        file_put_contents($file, $content);
        require_once $file;

        $blueprint = new Blueprint();
        $pdo = $this->memoryPdo();
        $connection = new Connection($pdo, DialectFactory::fromPdo($pdo));
        (new \Migration\Db\Migration_2_widgets())($connection, $blueprint);

        $operations = $blueprint->getOperations();
        static::assertCount(2, $operations);
        static::assertInstanceOf(DropIndexOperation::class, $operations[0]);
        static::assertSame('idx_widgets_name', $operations[0]->index->name);
        static::assertSame(['name'], $operations[0]->index->columns);
        static::assertTrue($operations[0]->index->unique);
        static::assertSame(['pgsql' => 'hash'], $operations[0]->index->algorithm);
        static::assertSame(['name' => 191], $operations[0]->index->lengths);
        static::assertSame('name IS NOT NULL', $operations[0]->index->where);
        static::assertInstanceOf(DropIndexOperation::class, $operations[1]);
        static::assertSame('idx_widgets_expr', $operations[1]->index->name);
        static::assertSame([], $operations[1]->index->columns);
        static::assertSame('(lower(name))', $operations[1]->index->expression);

        $pdo->exec('CREATE TABLE widgets (name TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX idx_widgets_name ON widgets(name)');
        $pdo->exec('CREATE INDEX idx_widgets_expr ON widgets((lower(name)))');
        new SchemaBlueprintRunner($pdo)->run(new \Migration\Db\Migration_2_widgets(), 'up');

        $schema = new \arabcoders\database\Schema\SchemaIntrospector($pdo)->introspect();
        static::assertNull($schema->getTable('widgets')?->getIndex('idx_widgets_name'));
        static::assertNull($schema->getTable('widgets')?->getIndex('idx_widgets_expr'));
    }
}
