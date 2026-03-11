<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MysqlDialectTest extends TestCase
{
    public function testMysqlDialectGeneratesSql(): void
    {
        $table = new TableDefinition(
            name: 'widgets',
            engine: ['mysql' => 'InnoDB'],
            charset: ['mysql' => 'utf8mb4'],
            collation: ['mysql' => 'utf8mb4_unicode_ci'],
        );

        $table->addColumn(new ColumnDefinition(
            name: 'id',
            type: ColumnType::Int,
            length: 11,
            unsigned: true,
            nullable: false,
            autoIncrement: true,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'title',
            type: ColumnType::VarChar,
            length: 255,
            nullable: false,
            hasDefault: true,
            default: '',
            comment: 'Title',
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'price',
            type: ColumnType::Decimal,
            precision: 8,
            scale: 2,
            nullable: false,
            hasDefault: true,
            default: 0,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'updated_at',
            type: ColumnType::DateTime,
            nullable: false,
            hasDefault: true,
            default: 'CURRENT_TIMESTAMP',
            defaultIsExpression: true,
            onUpdate: 'CURRENT_TIMESTAMP',
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'user_id',
            type: ColumnType::Int,
            length: 11,
            nullable: false,
        ));
        $table->setPrimaryKey(['id']);

        $table->addIndex(new IndexDefinition('idx_widgets_title', ['title'], unique: false, type: 'index', algorithm: [
            'mysql' => 'btree',
        ]));
        $table->addIndex(new IndexDefinition('uniq_widgets_title', ['title'], unique: true, type: 'index', algorithm: ['mysql' => 'hash']));
        $table->addIndex(new IndexDefinition('ft_widgets_title', ['title'], unique: false, type: 'fulltext'));

        $table->addForeignKey(new ForeignKeyDefinition(
            name: 'fk_widgets_user',
            columns: ['user_id'],
            referencesTable: 'users',
            referencesColumns: ['id'],
            onDelete: 'cascade',
            onUpdate: 'restrict',
        ));

        $dialect = new MysqlDialect();

        $createSql = $dialect->createTableSql($table);
        static::assertStringContainsString('CREATE TABLE `widgets`', $createSql);
        static::assertStringContainsString('ENGINE=InnoDB', $createSql);
        static::assertStringContainsString('DEFAULT CHARSET=utf8mb4', $createSql);
        static::assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $createSql);
        static::assertStringContainsString('PRIMARY KEY (`id`)', $createSql);
        static::assertStringContainsString('AUTO_INCREMENT', $createSql);
        static::assertStringContainsString('COMMENT', $createSql);
        static::assertStringContainsString('ON UPDATE CURRENT_TIMESTAMP', $createSql);
        static::assertStringContainsString('FOREIGN KEY (`user_id`)', $createSql);

        $titleColumn = $table->getColumn('title');
        static::assertNotNull($titleColumn);

        static::assertStringContainsString('ALTER TABLE `widgets` ADD COLUMN', $dialect->addColumnSql('widgets', $titleColumn));
        static::assertStringContainsString('ALTER TABLE `widgets` MODIFY COLUMN', $dialect->alterColumnSql('widgets', $titleColumn));
        static::assertStringContainsString('ALTER TABLE `widgets` DROP COLUMN `title`', $dialect->dropColumnSql('widgets', 'title'));

        $index = $table->getIndex('idx_widgets_title');
        $uniqueIndex = $table->getIndex('uniq_widgets_title');
        $fulltextIndex = $table->getIndex('ft_widgets_title');
        static::assertNotNull($index);
        static::assertNotNull($uniqueIndex);
        static::assertNotNull($fulltextIndex);

        static::assertStringContainsString('CREATE INDEX', $dialect->addIndexSql('widgets', $index));
        static::assertStringContainsString('CREATE UNIQUE INDEX', $dialect->addIndexSql('widgets', $uniqueIndex));
        static::assertStringContainsString('CREATE FULLTEXT INDEX', $dialect->addIndexSql('widgets', $fulltextIndex));
        static::assertStringContainsString('DROP INDEX', $dialect->dropIndexSql('widgets', $index));

        $foreignKey = $table->getForeignKey('fk_widgets_user');
        static::assertNotNull($foreignKey);
        static::assertStringContainsString('ADD CONSTRAINT `fk_widgets_user`', $dialect->addForeignKeySql('widgets', $foreignKey));
        static::assertStringContainsString('DROP FOREIGN KEY `fk_widgets_user`', $dialect->dropForeignKeySql('widgets', $foreignKey));

        static::assertStringContainsString('ADD PRIMARY KEY', $dialect->addPrimaryKeySql('widgets', ['id']));
        static::assertStringContainsString('DROP PRIMARY KEY', $dialect->dropPrimaryKeySql('widgets'));

        static::assertStringContainsString('RENAME TABLE', $dialect->renameTableSql('old_widgets', 'widgets'));
        static::assertStringContainsString('RENAME COLUMN', $dialect->renameColumnSql('widgets', 'fieldFoo', 'field_foo'));

        static::assertTrue($dialect->supportsAlterColumn());
        static::assertTrue($dialect->supportsDropColumn());
        static::assertTrue($dialect->supportsForeignKeys());
        static::assertTrue($dialect->supportsPrimaryKeyAlter());
    }

    public function testMysqlDialectRendersGeneratedAndExpressionIndex(): void
    {
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'name',
            type: ColumnType::VarChar,
            length: 255,
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'name_lower',
            type: ColumnType::VarChar,
            length: 255,
            generated: true,
            generatedExpression: 'lower(name)',
            generatedStored: false,
        ));

        $dialect = new MysqlDialect();
        $createSql = $dialect->createTableSql($table);
        static::assertStringContainsString('GENERATED ALWAYS AS (lower(name)) VIRTUAL', $createSql);

        $index = new IndexDefinition(
            name: 'idx_widgets_name_expr',
            columns: [],
            expression: 'lower(name)',
        );

        $indexSql = $dialect->addIndexSql('widgets', $index);
        static::assertStringContainsString('CREATE INDEX', $indexSql);
        static::assertStringContainsString('(lower(name))', $indexSql);
    }

    public function testMysqlDialectRejectsUnsupportedPredicateIndex(): void
    {
        $dialect = new MysqlDialect();

        $this->expectException(RuntimeException::class);
        $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_partial',
            columns: ['name'],
            where: 'deleted_at IS NULL',
        ));
    }
}
