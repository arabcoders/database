<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Dialect\PostgresDialect;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\RebuildTableOperation;
use arabcoders\database\Schema\SchemaDiff;
use arabcoders\database\Schema\SchemaDiffer;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PHPUnit\Framework\TestCase;

final class SchemaSqlRendererTest extends TestCase
{
    public function testSqliteRendererUsesRebuildForColumnChanges(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $fromTable->setPrimaryKey(['id']);
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $toTable->addColumn(new ColumnDefinition('description', ColumnType::Text, nullable: true));
        $toTable->setPrimaryKey(['id']);
        $toSchema->addTable($toTable);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new SqliteDialect());
        $sql = $renderer->render($diff);

        $hasRename = false;
        foreach ($sql->up as $statement) {
            if (str_contains($statement, 'RENAME TO')) {
                $hasRename = true;
                break;
            }
        }

        static::assertTrue($hasRename);
    }

    public function testMysqlRendererGeneratesSqlForOperations(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::VarChar, length: 100));
        $fromTable->addColumn(new ColumnDefinition('legacy', ColumnType::Text, nullable: true));
        $fromTable->addColumn(new ColumnDefinition('user_id', ColumnType::Int, length: 11));
        $fromTable->setPrimaryKey(['id']);
        $fromTable->addIndex(new IndexDefinition('idx_widgets_name', ['name']));
        $fromTable->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'restrict', 'restrict'));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::VarChar, length: 255));
        $toTable->addColumn(new ColumnDefinition('description', ColumnType::Text, nullable: true));
        $toTable->addColumn(new ColumnDefinition('user_id', ColumnType::Int, length: 11));
        $toTable->setPrimaryKey(['id', 'user_id']);
        $toTable->addIndex(new IndexDefinition('idx_widgets_user', ['user_id']));
        $toTable->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'cascade', 'restrict'));
        $toSchema->addTable($toTable);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new MysqlDialect());
        $sql = $renderer->render($diff);

        $upSql = implode("\n", $sql->up);
        static::assertStringContainsString('ALTER TABLE `widgets` DROP COLUMN `legacy`', $upSql);
        static::assertStringContainsString('ALTER TABLE `widgets` ADD COLUMN', $upSql);
        static::assertStringContainsString('ALTER TABLE `widgets` MODIFY COLUMN', $upSql);
        static::assertStringContainsString('DROP INDEX `idx_widgets_name`', $upSql);
        static::assertStringContainsString('CREATE INDEX `idx_widgets_user`', $upSql);
        static::assertStringContainsString('DROP FOREIGN KEY `fk_widgets_user`', $upSql);
        static::assertStringContainsString('ADD CONSTRAINT `fk_widgets_user`', $upSql);
        static::assertStringContainsString('DROP PRIMARY KEY', $upSql);
        static::assertStringContainsString('ADD PRIMARY KEY', $upSql);

        $downSql = implode("\n", $sql->down);
        static::assertStringContainsString('ADD COLUMN', $downSql);
        static::assertStringContainsString('DROP COLUMN', $downSql);
    }

    public function testMysqlRendererHandlesRenameOperations(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('legacy_widgets');
        $fromTable->addColumn(new ColumnDefinition('fieldFoo', ColumnType::VarChar, length: 10));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets', previousName: 'legacy_widgets');
        $toTable->addColumn(new ColumnDefinition(
            name: 'field_foo',
            type: ColumnType::VarChar,
            length: 10,
            previousName: 'fieldFoo',
        ));
        $toSchema->addTable($toTable);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new MysqlDialect());
        $sql = $renderer->render($diff);

        $upSql = implode("\n", $sql->up);
        static::assertStringContainsString('RENAME TABLE `legacy_widgets` TO `widgets`', $upSql);
        static::assertStringContainsString('RENAME COLUMN `fieldFoo` TO `field_foo`', $upSql);
    }

    public function testMysqlRendererSupportsEnumSetAndChecks(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'status',
            type: ColumnType::Enum,
            allowed: ['draft', 'published'],
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'flags',
            type: ColumnType::Set,
            allowed: ['a', 'b'],
            nullable: true,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'score',
            type: ColumnType::Int,
            check: true,
            checkExpression: 'score >= 0',
        ));

        $sql = new MysqlDialect()->createTableSql($table);

        static::assertStringContainsString('`status` enum', $sql);
        static::assertStringContainsString("'draft'", $sql);
        static::assertStringContainsString("'published'", $sql);
        static::assertStringContainsString('`flags` set', $sql);
        static::assertStringContainsString('CHECK (`status` IN', $sql);
        static::assertStringContainsString('CHECK (score >= 0)', $sql);
    }

    public function testPostgresRendererSupportsConstraintsAndNetworkTypes(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'status',
            type: ColumnType::Enum,
            allowed: ['draft', 'published'],
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'ip',
            type: ColumnType::IpAddress,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'mac',
            type: ColumnType::MacAddress,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'uuid',
            type: ColumnType::Uuid,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'score',
            type: ColumnType::Int,
            check: true,
            checkExpression: 'score >= 0',
        ));

        $sql = new PostgresDialect()->createTableSql($table);

        static::assertStringContainsString('"status" text', $sql);
        static::assertStringContainsString('"ip" inet', $sql);
        static::assertStringContainsString('"mac" macaddr', $sql);
        static::assertStringContainsString('"uuid" uuid', $sql);
        static::assertStringContainsString('CHECK ("status" IN', $sql);
        static::assertStringContainsString('CHECK (score >= 0)', $sql);
    }

    public function testSqliteRendererSupportsChecks(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'status',
            type: ColumnType::Enum,
            allowed: ['draft', 'published'],
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'score',
            type: ColumnType::Int,
            check: true,
            checkExpression: 'score >= 0',
        ));

        $sql = new SqliteDialect()->createTableSql($table);

        static::assertStringContainsString('"status" TEXT', $sql);
        static::assertStringContainsString('CHECK ("status" IN', $sql);
        static::assertStringContainsString('CHECK (score >= 0)', $sql);
    }

    public function testSqliteRendererHandlesRenameColumn(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('fieldFoo', ColumnType::Text));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition(
            name: 'field_foo',
            type: ColumnType::Text,
            previousName: 'fieldFoo',
        ));
        $toSchema->addTable($toTable);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new SqliteDialect());
        $sql = $renderer->render($diff);

        $joined = implode("\n", $sql->up);
        static::assertStringContainsString('RENAME COLUMN', $joined);
    }

    public function testRebuildOperationExposesMetadata(): void
    {
        $fromTable = new TableDefinition('widgets');
        $toTable = new TableDefinition('widgets');
        $operation = new RebuildTableOperation($fromTable, $toTable);

        static::assertSame('rebuild_table', $operation->getType());
        static::assertSame('widgets', $operation->getTableName());
    }

    public function testRendererFlattensArrayReturnsFromDialect(): void
    {
        // Test that SchemaSqlRenderer properly flattens arrays returned from dialect methods
        $diff = new SchemaDiff(new SchemaDefinition(), new SchemaDefinition(), [
            new AddIndexOperation('users', new IndexDefinition(
                'uniq_users_email',
                ['email'],
                unique: true,
                type: 'index',
                algorithm: ['pgsql' => 'hash'],
            )),
        ]);

        $renderer = new SchemaSqlRenderer(new PostgresDialect());
        $sql = $renderer->render($diff);

        // All statements should be strings (flattened)
        foreach ($sql->up as $statement) {
            static::assertIsString($statement);
        }
        foreach ($sql->down as $statement) {
            static::assertIsString($statement);
        }

        // Should contain CREATE UNIQUE INDEX with BTREE (hash falls back to btree for unique)
        $upSql = implode("\n", $sql->up);
        static::assertStringContainsString('CREATE UNIQUE INDEX', $upSql);
        static::assertStringContainsString('USING BTREE', $upSql);
    }
}
