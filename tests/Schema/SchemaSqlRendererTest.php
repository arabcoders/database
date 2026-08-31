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
use tests\TestCase;

final class SchemaSqlRendererTest extends TestCase
{
    public function testSqliteUsesRebuild(): void
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
        static::assertSame(
            [
                'ALTER TABLE "widgets" RENAME TO "_tmp_widgets_old"',
                "CREATE TABLE \"widgets\" (\n    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,\n    \"name\" TEXT NOT NULL,\n    \"description\" TEXT NULL\n)",
                'INSERT INTO "widgets" ("id", "name") SELECT "id", "name" FROM "_tmp_widgets_old"',
                'DROP TABLE "_tmp_widgets_old"',
            ],
            $sql->up,
        );
    }

    public function testMysqlGeneratesSql(): void
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

        static::assertSame(
            [
                'ALTER TABLE `widgets` DROP FOREIGN KEY `fk_widgets_user`',
                'DROP INDEX `idx_widgets_name` ON `widgets`',
                'ALTER TABLE `widgets` DROP PRIMARY KEY',
                'ALTER TABLE `widgets` DROP COLUMN `legacy`',
                'ALTER TABLE `widgets` ADD COLUMN `description` text NULL',
                'ALTER TABLE `widgets` MODIFY COLUMN `name` varchar(255) NOT NULL',
                'ALTER TABLE `widgets` ADD PRIMARY KEY (`id`, `user_id`)',
                'CREATE INDEX `idx_widgets_user` ON `widgets` (`user_id`)',
                'ALTER TABLE `widgets` ADD CONSTRAINT `fk_widgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT',
            ],
            $sql->up,
        );
        static::assertSame(
            [
                'ALTER TABLE `widgets` DROP FOREIGN KEY `fk_widgets_user`',
                'DROP INDEX `idx_widgets_user` ON `widgets`',
                'ALTER TABLE `widgets` DROP PRIMARY KEY',
                'ALTER TABLE `widgets` MODIFY COLUMN `name` varchar(100) NOT NULL',
                'ALTER TABLE `widgets` DROP COLUMN `description`',
                'ALTER TABLE `widgets` ADD COLUMN `legacy` text NULL',
                'ALTER TABLE `widgets` ADD PRIMARY KEY (`id`)',
                'CREATE INDEX `idx_widgets_name` ON `widgets` (`name`)',
                'ALTER TABLE `widgets` ADD CONSTRAINT `fk_widgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
            ],
            $sql->down,
        );
    }

    public function testMysqlDefersForeign(): void
    {
        $fromSchema = new SchemaDefinition();

        $toSchema = new SchemaDefinition();

        $foo = new TableDefinition('foo');
        $foo->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $foo->addColumn(new ColumnDefinition('bar_id', ColumnType::Int, length: 11));
        $foo->setPrimaryKey(['id']);
        $foo->addForeignKey(new ForeignKeyDefinition('fk_foo_bar', ['bar_id'], 'bar', ['id']));
        $toSchema->addTable($foo);

        $bar = new TableDefinition('bar');
        $bar->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $bar->setPrimaryKey(['id']);
        $toSchema->addTable($bar);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new MysqlDialect());
        $sql = $renderer->render($diff);
        static::assertSame(
            [
                "CREATE TABLE `foo` (\n    `id` int(11) NOT NULL AUTO_INCREMENT,\n    `bar_id` int(11) NOT NULL,\n    PRIMARY KEY (`id`)\n)",
                "CREATE TABLE `bar` (\n    `id` int(11) NOT NULL AUTO_INCREMENT,\n    PRIMARY KEY (`id`)\n)",
                'ALTER TABLE `foo` ADD CONSTRAINT `fk_foo_bar` FOREIGN KEY (`bar_id`) REFERENCES `bar` (`id`)',
            ],
            $sql->up,
        );
        static::assertSame(
            [
                'ALTER TABLE `foo` DROP FOREIGN KEY `fk_foo_bar`',
                'DROP TABLE IF EXISTS `bar`',
                'DROP TABLE IF EXISTS `foo`',
            ],
            $sql->down,
        );
    }

    public function testPostgresDefersForeign(): void
    {
        $fromSchema = new SchemaDefinition();

        $toSchema = new SchemaDefinition();

        $foo = new TableDefinition('foo');
        $foo->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $foo->addColumn(new ColumnDefinition('bar_id', ColumnType::Int, length: 11));
        $foo->setPrimaryKey(['id']);
        $foo->addForeignKey(new ForeignKeyDefinition('fk_foo_bar', ['bar_id'], 'bar', ['id']));
        $toSchema->addTable($foo);

        $bar = new TableDefinition('bar');
        $bar->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $bar->setPrimaryKey(['id']);
        $toSchema->addTable($bar);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $renderer = new SchemaSqlRenderer(new PostgresDialect());
        $sql = $renderer->render($diff);
        static::assertSame(
            [
                "CREATE TABLE \"foo\" (\n    \"id\" integer NOT NULL GENERATED BY DEFAULT AS IDENTITY,\n    \"bar_id\" integer NOT NULL,\n    PRIMARY KEY (\"id\")\n)",
                "CREATE TABLE \"bar\" (\n    \"id\" integer NOT NULL GENERATED BY DEFAULT AS IDENTITY,\n    PRIMARY KEY (\"id\")\n)",
                'ALTER TABLE "foo" ADD CONSTRAINT "fk_foo_bar" FOREIGN KEY ("bar_id") REFERENCES "bar" ("id")',
            ],
            $sql->up,
        );
        static::assertSame(
            [
                'ALTER TABLE "foo" DROP CONSTRAINT "fk_foo_bar"',
                'DROP TABLE IF EXISTS "bar"',
                'DROP TABLE IF EXISTS "foo"',
            ],
            $sql->down,
        );
    }

    public function testMysqlHandlesRename(): void
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
        static::assertSame(
            [
                'RENAME TABLE `legacy_widgets` TO `widgets`',
                'ALTER TABLE `widgets` RENAME COLUMN `fieldFoo` TO `field_foo`',
            ],
            $sql->up,
        );
        static::assertSame(
            [
                'ALTER TABLE `widgets` RENAME COLUMN `field_foo` TO `fieldFoo`',
                'RENAME TABLE `widgets` TO `legacy_widgets`',
            ],
            $sql->down,
        );
    }

    public function testMysqlSupportsEnum(): void
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

        static::assertSame(
            "CREATE TABLE `widgets` (\n    `status` enum('draft', 'published') NOT NULL CHECK (`status` IN ('draft', 'published')),\n    `flags` set('a', 'b') NULL CHECK (`flags` IN ('a', 'b')),\n    `score` int NOT NULL CHECK (score >= 0)\n)",
            $sql,
        );
    }

    public function testPostgresSupportsConstraints(): void
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

        static::assertSame(
            "CREATE TABLE \"widgets\" (\n    \"status\" text NOT NULL CHECK (\"status\" IN ('draft', 'published')),\n    \"ip\" inet NOT NULL,\n    \"mac\" macaddr NOT NULL,\n    \"uuid\" uuid NOT NULL,\n    \"score\" integer NOT NULL CHECK (score >= 0)\n)",
            $sql,
        );
    }

    public function testSqliteSupportsChecks(): void
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

        static::assertSame(
            "CREATE TABLE \"widgets\" (\n    \"status\" TEXT NOT NULL CHECK (\"status\" IN ('draft', 'published')),\n    \"score\" INTEGER NOT NULL CHECK (score >= 0)\n)",
            $sql,
        );
    }

    public function testSqliteHandlesRename(): void
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
        static::assertSame(['ALTER TABLE "widgets" RENAME COLUMN "fieldFoo" TO "field_foo"'], $sql->up);
    }

    public function testSqliteRebuildCopies(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int));
        $fromTable->addColumn(new ColumnDefinition('legacy_name', ColumnType::Text));
        $fromTable->addColumn(new ColumnDefinition('obsolete', ColumnType::Text, nullable: true));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::Text, previousName: 'legacy_name'));
        $toSchema->addTable($toTable);

        $sql = new SchemaSqlRenderer(new SqliteDialect())->render(new SchemaDiffer()->diff($fromSchema, $toSchema));
        static::assertSame(
            [
                'ALTER TABLE "widgets" RENAME TO "_tmp_widgets_old"',
                "CREATE TABLE \"widgets\" (\n    \"id\" INTEGER NOT NULL,\n    \"name\" TEXT NOT NULL\n)",
                'INSERT INTO "widgets" ("id", "name") SELECT "id", "legacy_name" FROM "_tmp_widgets_old"',
                'DROP TABLE "_tmp_widgets_old"',
            ],
            $sql->up,
        );
        static::assertSame(
            [
                'ALTER TABLE "widgets" RENAME TO "_tmp_widgets_old"',
                "CREATE TABLE \"widgets\" (\n    \"id\" INTEGER NOT NULL,\n    \"legacy_name\" TEXT NOT NULL,\n    \"obsolete\" TEXT NULL\n)",
                'INSERT INTO "widgets" ("id", "legacy_name") SELECT "id", "name" FROM "_tmp_widgets_old"',
                'DROP TABLE "_tmp_widgets_old"',
            ],
            $sql->down,
        );
    }

    public function testRebuildOperationExposes(): void
    {
        $fromTable = new TableDefinition('widgets');
        $toTable = new TableDefinition('widgets');
        $operation = new RebuildTableOperation($fromTable, $toTable);

        static::assertSame('rebuild_table', $operation->getType());
        static::assertSame('widgets', $operation->getTableName());
    }

    public function testFlattensDialectStatements(): void
    {
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
        static::assertSame(['CREATE UNIQUE INDEX "uniq_users_email" ON "users" USING BTREE ("email")'], $sql->up);
        static::assertSame(['DROP INDEX IF EXISTS "uniq_users_email"'], $sql->down);
    }

    public function testRollsBackDropped(): void
    {
        $blueprint = new Blueprint();
        $blueprint->table('widgets', static function ($table): void {
            $table->dropIndex(name: 'idx_widgets_name', columns: ['name']);
        });

        $diff = $blueprint->toDiff();

        foreach ([new MysqlDialect(), new SqliteDialect(), new PostgresDialect()] as $dialect) {
            $sql = new SchemaSqlRenderer($dialect)->render($diff);
            static::assertSame(
                match ($dialect->name()) {
                    'mysql' => ['DROP INDEX `idx_widgets_name` ON `widgets`'],
                    'sqlite' => ['DROP INDEX IF EXISTS "idx_widgets_name"'],
                    'pgsql' => ['DROP INDEX IF EXISTS "idx_widgets_name"'],
                },
                $sql->up,
            );
            static::assertSame(
                match ($dialect->name()) {
                    'mysql' => ['CREATE INDEX `idx_widgets_name` ON `widgets` (`name`)'],
                    'sqlite' => ['CREATE INDEX "idx_widgets_name" ON "widgets" ("name")'],
                    'pgsql' => ['CREATE INDEX "idx_widgets_name" ON "widgets" USING BTREE ("name")'],
                },
                $sql->down,
            );
        }
    }
}
