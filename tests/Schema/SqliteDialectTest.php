<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqliteDialectTest extends TestCase
{
    public function testSqliteDialectGeneratesSql(): void
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
        static::assertStringContainsString('PRIMARY KEY', $createSql);
        static::assertStringContainsString('AUTOINCREMENT', $createSql);
        static::assertStringContainsString('FOREIGN KEY', $createSql);

        $index = $table->getIndex('idx_widgets_name');
        static::assertNotNull($index);
        static::assertStringContainsString('CREATE INDEX', $dialect->addIndexSql('widgets', $index));
        static::assertStringContainsString('DROP INDEX', $dialect->dropIndexSql('widgets', $index));

        $createdColumn = $table->getColumn('created_at');
        static::assertNotNull($createdColumn);
        static::assertStringContainsString('ADD COLUMN', $dialect->addColumnSql('widgets', $createdColumn));
        static::assertSame('', $dialect->alterColumnSql('widgets', $createdColumn));
        static::assertSame('', $dialect->dropColumnSql('widgets', 'created_at'));
        $foreignKey = new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id']);
        static::assertSame('', $dialect->addForeignKeySql('widgets', $foreignKey));
        static::assertSame('', $dialect->dropForeignKeySql('widgets', $foreignKey));
        static::assertSame('', $dialect->addPrimaryKeySql('widgets', ['id']));
        static::assertSame('', $dialect->dropPrimaryKeySql('widgets'));
        static::assertStringContainsString('DROP TABLE', $dialect->dropTableSql('widgets'));
        static::assertStringContainsString('RENAME TO', $dialect->renameTableSql('old_widgets', 'widgets'));
        static::assertStringContainsString('RENAME COLUMN', $dialect->renameColumnSql('widgets', 'fieldFoo', 'field_foo'));

        static::assertFalse($dialect->supportsAlterColumn());
        static::assertFalse($dialect->supportsDropColumn());
        static::assertFalse($dialect->supportsForeignKeys());
        static::assertFalse($dialect->supportsPrimaryKeyAlter());
    }

    public function testRebuildTableSqlCopiesDataAndIndexes(): void
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

        $joined = implode("\n", $sql);
        static::assertStringContainsString('RENAME TO', $joined);
        static::assertStringContainsString('INSERT INTO', $joined);
        static::assertStringContainsString('CREATE INDEX', $joined);
    }

    public function testSqliteDialectSupportsPartialAndExpressionIndexes(): void
    {
        $dialect = new SqliteDialect();

        $partialSql = $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_partial',
            columns: ['name'],
            where: 'deleted_at IS NULL',
        ));
        static::assertStringContainsString('WHERE deleted_at IS NULL', $partialSql);

        $expressionSql = $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_expr',
            columns: [],
            expression: '(lower(name))',
        ));
        static::assertStringContainsString('((lower(name)))', $expressionSql);
    }

    public function testSqliteDialectRejectsUnsupportedIndexType(): void
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
