<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use PDO;
use RuntimeException;
use tests\TestCase;

final class SqliteDialectTest extends TestCase
{
    public function testGeneratesSql(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $table->addColumn(new ColumnDefinition('name', ColumnType::Text, nullable: false));
        $table->addColumn(new ColumnDefinition('user_id', ColumnType::Int, nullable: false));
        $table->addColumn(new ColumnDefinition(
            name: 'created_at',
            type: ColumnType::DateTime,
            nullable: false,
            hasDefault: true,
            default: 'CURRENT_TIMESTAMP',
            defaultIsExpression: true,
            collation: ['default' => 'NOCASE'],
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'ratio',
            type: ColumnType::Decimal,
            precision: 5,
            scale: 2,
            hasDefault: true,
            default: 1.5,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'is_active',
            type: ColumnType::Boolean,
            hasDefault: true,
            default: true,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'notes',
            type: ColumnType::Text,
            hasDefault: true,
            default: 'ok',
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'maybe',
            type: ColumnType::Text,
            hasDefault: true,
            default: null,
        ));
        $table->setPrimaryKey(['id']);
        $table->addIndex(new IndexDefinition('idx_widgets_name', ['name']));
        $table->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'cascade', 'restrict'));

        $dialect = new SqliteDialect();

        $createSql = $dialect->createTableSql($table);
        $pdo = $this->memoryPdo();
        $pdo->exec($createSql);
        static::assertSame(
            ['id', 'name', 'user_id', 'created_at', 'ratio', 'is_active', 'notes', 'maybe'],
            array_column($pdo->query('PRAGMA table_info("widgets")')->fetchAll(PDO::FETCH_ASSOC), 'name'),
        );

        $index = $table->getIndex('idx_widgets_name');
        static::assertNotNull($index);
        static::assertSame('CREATE INDEX "idx_widgets_name" ON "widgets" ("name")', $dialect->addIndexSql('widgets', $index));
        static::assertSame('DROP INDEX IF EXISTS "idx_widgets_name"', $dialect->dropIndexSql('widgets', $index));

        $createdColumn = $table->getColumn('created_at');
        static::assertNotNull($createdColumn);
        static::assertSame('ALTER TABLE "widgets" ADD COLUMN "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $dialect->addColumnSql(
            'widgets',
            $createdColumn,
        ));
        static::assertSame('', $dialect->alterColumnSql('widgets', $createdColumn));
        static::assertSame('', $dialect->dropColumnSql('widgets', 'created_at'));
        $foreignKey = new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id']);
        static::assertSame('', $dialect->addForeignKeySql('widgets', $foreignKey));
        static::assertSame('', $dialect->dropForeignKeySql('widgets', $foreignKey));
        static::assertSame('', $dialect->addPrimaryKeySql('widgets', ['id']));
        static::assertSame('', $dialect->dropPrimaryKeySql('widgets'));
        static::assertSame('DROP TABLE IF EXISTS "widgets"', $dialect->dropTableSql('widgets'));
        static::assertSame('ALTER TABLE "old_widgets" RENAME TO "widgets"', $dialect->renameTableSql('old_widgets', 'widgets'));
        static::assertSame('ALTER TABLE "widgets" RENAME COLUMN "fieldFoo" TO "field_foo"', $dialect->renameColumnSql(
            'widgets',
            'fieldFoo',
            'field_foo',
        ));

        static::assertFalse($dialect->supportsAlterColumn());
        static::assertFalse($dialect->supportsDropColumn());
        static::assertFalse($dialect->supportsForeignKeys());
        static::assertFalse($dialect->supportsPrimaryKeyAlter());
    }

    public function testRebuildTableSql(): void
    {
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $fromTable->setPrimaryKey(['id']);

        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $toTable->addColumn(new ColumnDefinition('description', ColumnType::Text, nullable: true));
        $toTable->setPrimaryKey(['id']);
        $toTable->addIndex(new IndexDefinition('idx_widgets_name', ['name']));

        $dialect = new SqliteDialect();
        $sql = $dialect->rebuildTableSql($fromTable, $toTable);

        static::assertSame(
            [
                'ALTER TABLE "widgets" RENAME TO "_tmp_widgets_old"',
                "CREATE TABLE \"widgets\" (\n    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,\n    \"name\" TEXT NOT NULL,\n    \"description\" TEXT NULL\n)",
                'INSERT INTO "widgets" ("id", "name") SELECT "id", "name" FROM "_tmp_widgets_old"',
                'DROP TABLE "_tmp_widgets_old"',
                'CREATE INDEX "idx_widgets_name" ON "widgets" ("name")',
            ],
            $sql,
        );
    }

    public function testSupportsPartialExpression(): void
    {
        $dialect = new SqliteDialect();

        $partialSql = $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_partial',
            columns: ['name'],
            where: 'deleted_at IS NULL',
        ));
        static::assertSame('CREATE INDEX "idx_widgets_partial" ON "widgets" ("name") WHERE deleted_at IS NULL', $partialSql);

        $expressionSql = $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_expr',
            columns: [],
            expression: '(lower(name))',
        ));
        static::assertSame('CREATE INDEX "idx_widgets_expr" ON "widgets" ((lower(name)))', $expressionSql);
    }

    public function testRejectsUnsupportedIndex(): void
    {
        $dialect = new SqliteDialect();

        $this->expectException(RuntimeException::class);
        $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'ft_widgets_name',
            columns: ['name'],
            type: 'fulltext',
        ));
    }
}
