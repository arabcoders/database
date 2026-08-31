<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use RuntimeException;
use tests\TestCase;

final class MysqlDialectTest extends TestCase
{
    public function testGeneratesSql(): void
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
        static::assertSame(
            "CREATE TABLE `widgets` (\n"
            . "    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,\n"
            . "    `title` varchar(255) NOT NULL DEFAULT '' COMMENT 'Title',\n"
            . "    `price` decimal(8,2) NOT NULL DEFAULT 0,\n"
            . "    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "    `user_id` int(11) NOT NULL,\n"
            . "    PRIMARY KEY (`id`),\n"
            . "    CONSTRAINT `fk_widgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT\n"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $createSql,
        );

        $titleColumn = $table->getColumn('title');
        static::assertNotNull($titleColumn);

        static::assertSame('ALTER TABLE `widgets` ADD COLUMN `title` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'Title\'', $dialect->addColumnSql(
            'widgets',
            $titleColumn,
        ));
        static::assertSame('ALTER TABLE `widgets` MODIFY COLUMN `title` varchar(255) NOT NULL DEFAULT \'\' COMMENT \'Title\'', $dialect->alterColumnSql(
            'widgets',
            $titleColumn,
        ));
        static::assertSame('ALTER TABLE `widgets` DROP COLUMN `title`', $dialect->dropColumnSql('widgets', 'title'));

        $index = $table->getIndex('idx_widgets_title');
        $uniqueIndex = $table->getIndex('uniq_widgets_title');
        $fulltextIndex = $table->getIndex('ft_widgets_title');
        static::assertNotNull($index);
        static::assertNotNull($uniqueIndex);
        static::assertNotNull($fulltextIndex);

        static::assertSame('CREATE INDEX `idx_widgets_title` USING BTREE ON `widgets` (`title`)', $dialect->addIndexSql('widgets', $index));
        static::assertSame('CREATE UNIQUE INDEX `uniq_widgets_title` USING HASH ON `widgets` (`title`)', $dialect->addIndexSql(
            'widgets',
            $uniqueIndex,
        ));
        static::assertSame('CREATE FULLTEXT INDEX `ft_widgets_title` ON `widgets` (`title`)', $dialect->addIndexSql(
            'widgets',
            $fulltextIndex,
        ));
        static::assertSame('DROP INDEX `idx_widgets_title` ON `widgets`', $dialect->dropIndexSql('widgets', $index));

        $foreignKey = $table->getForeignKey('fk_widgets_user');
        static::assertNotNull($foreignKey);
        static::assertSame(
            'ALTER TABLE `widgets` ADD CONSTRAINT `fk_widgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT',
            $dialect->addForeignKeySql('widgets', $foreignKey),
        );
        static::assertSame('ALTER TABLE `widgets` DROP FOREIGN KEY `fk_widgets_user`', $dialect->dropForeignKeySql('widgets', $foreignKey));

        static::assertSame('ALTER TABLE `widgets` ADD PRIMARY KEY (`id`)', $dialect->addPrimaryKeySql('widgets', ['id']));
        static::assertSame('ALTER TABLE `widgets` DROP PRIMARY KEY', $dialect->dropPrimaryKeySql('widgets'));

        static::assertSame('RENAME TABLE `old_widgets` TO `widgets`', $dialect->renameTableSql('old_widgets', 'widgets'));
        static::assertSame('ALTER TABLE `widgets` RENAME COLUMN `fieldFoo` TO `field_foo`', $dialect->renameColumnSql(
            'widgets',
            'fieldFoo',
            'field_foo',
        ));

        static::assertTrue($dialect->supportsAlterColumn());
        static::assertTrue($dialect->supportsDropColumn());
        static::assertTrue($dialect->supportsForeignKeys());
        static::assertTrue($dialect->supportsPrimaryKeyAlter());
    }

    public function testRendersGeneratedIndex(): void
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
        static::assertSame(
            "CREATE TABLE `widgets` (\n"
            . "    `name` varchar(255) NOT NULL,\n"
            . "    `name_lower` varchar(255) GENERATED ALWAYS AS (lower(name)) VIRTUAL NOT NULL\n"
            . ')',
            $createSql,
        );

        $index = new IndexDefinition(
            name: 'idx_widgets_name_expr',
            columns: [],
            expression: 'lower(name)',
        );

        $indexSql = $dialect->addIndexSql('widgets', $index);
        static::assertSame('CREATE INDEX `idx_widgets_name_expr` ON `widgets` ((lower(name)))', $indexSql);
    }

    public function testRejectsPredicateIndex(): void
    {
        $dialect = new MysqlDialect();

        $this->expectException(RuntimeException::class);
        $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_partial',
            columns: ['name'],
            where: 'deleted_at IS NULL',
        ));
    }

    public function testRendersColumnPrefixes(): void
    {
        $dialect = new MysqlDialect();

        $sql = $dialect->addIndexSql('widgets', new IndexDefinition(
            name: 'idx_widgets_simple_url',
            columns: ['simple_url'],
            lengths: ['simple_url' => 191],
        ));

        static::assertSame('CREATE INDEX `idx_widgets_simple_url` ON `widgets` (`simple_url`(191))', $sql);
    }
}
