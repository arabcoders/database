<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\SchemaIntrospector;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaIntrospectorMysqlTest extends TestCase
{
    public function testMysqlIntrospectionBuildsSchema(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $databaseStmt = $this->createStub(PDOStatement::class);
        $databaseStmt->method('fetchColumn')->willReturn('dash_test');
        $pdo->method('query')->willReturn($databaseStmt);

        $tablesRows = [
            ['TABLE_NAME' => 'widgets', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ];

        $columnsRows = [
            [
                'COLUMN_NAME' => 'id',
                'COLUMN_TYPE' => 'int(11) unsigned',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => 'auto_increment',
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_COMMENT' => '',
            ],
            [
                'COLUMN_NAME' => 'name',
                'COLUMN_TYPE' => 'varchar(255)',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => 'guest',
                'EXTRA' => '',
                'CHARACTER_SET_NAME' => 'utf8mb4',
                'COLLATION_NAME' => 'utf8mb4_unicode_ci',
                'COLUMN_COMMENT' => 'Name',
            ],
            [
                'COLUMN_NAME' => 'user_id',
                'COLUMN_TYPE' => 'int(11)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_COMMENT' => '',
            ],
            [
                'COLUMN_NAME' => 'updated_at',
                'COLUMN_TYPE' => 'datetime',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => 'CURRENT_TIMESTAMP',
                'EXTRA' => 'on update CURRENT_TIMESTAMP',
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_COMMENT' => '',
            ],
            [
                'COLUMN_NAME' => 'price',
                'COLUMN_TYPE' => 'decimal(10,2)',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => '0.00',
                'EXTRA' => '',
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_COMMENT' => '',
            ],
            [
                'COLUMN_NAME' => 'status',
                'COLUMN_TYPE' => "enum('draft','published')",
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => 'draft',
                'EXTRA' => '',
                'CHARACTER_SET_NAME' => null,
                'COLLATION_NAME' => null,
                'COLUMN_COMMENT' => '',
                'GENERATION_EXPRESSION' => null,
            ],
            [
                'COLUMN_NAME' => 'name_lower',
                'COLUMN_TYPE' => 'varchar(255)',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => 'VIRTUAL GENERATED',
                'CHARACTER_SET_NAME' => 'utf8mb4',
                'COLLATION_NAME' => 'utf8mb4_unicode_ci',
                'COLUMN_COMMENT' => '',
                'GENERATION_EXPRESSION' => 'lower(`name`)',
            ],
        ];

        $indexesRows = [
            ['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'INDEX_TYPE' => 'BTREE', 'COLUMN_NAME' => 'id', 'SEQ_IN_INDEX' => 1],
            ['INDEX_NAME' => 'idx_widgets_name', 'NON_UNIQUE' => 1, 'INDEX_TYPE' => 'BTREE', 'COLUMN_NAME' => 'name', 'SEQ_IN_INDEX' => 1],
        ];

        $foreignRows = [
            [
                'CONSTRAINT_NAME' => 'fk_widgets_user',
                'COLUMN_NAME' => 'user_id',
                'REFERENCED_TABLE_NAME' => 'users',
                'REFERENCED_COLUMN_NAME' => 'id',
                'UPDATE_RULE' => 'RESTRICT',
                'DELETE_RULE' => 'CASCADE',
                'ORDINAL_POSITION' => 1,
            ],
        ];

        $tablesStmt = $this->createStub(PDOStatement::class);
        $tablesStmt->method('execute')->willReturn(true);
        $tablesStmt->method('fetchAll')->willReturn($tablesRows);

        $columnsStmt = $this->createStub(PDOStatement::class);
        $columnsStmt->method('execute')->willReturn(true);
        $columnsStmt->method('fetchAll')->willReturn($columnsRows);

        $indexesStmt = $this->createStub(PDOStatement::class);
        $indexesStmt->method('execute')->willReturn(true);
        $indexesStmt->method('fetchAll')->willReturn($indexesRows);

        $foreignStmt = $this->createStub(PDOStatement::class);
        $foreignStmt->method('execute')->willReturn(true);
        $foreignStmt->method('fetchAll')->willReturn($foreignRows);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($tablesStmt, $columnsStmt, $indexesStmt, $foreignStmt) {
            if (str_contains($sql, 'information_schema.TABLES')) {
                return $tablesStmt;
            }
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return $columnsStmt;
            }
            if (str_contains($sql, 'information_schema.STATISTICS')) {
                return $indexesStmt;
            }
            if (str_contains($sql, 'information_schema.KEY_COLUMN_USAGE')) {
                return $foreignStmt;
            }

            throw new RuntimeException('Unexpected SQL: ' . $sql);
        });

        $schema = new SchemaIntrospector($pdo)->introspect();
        $table = $schema->getTable('widgets');

        static::assertNotNull($table);
        static::assertSame(['id'], $table->getPrimaryKey());
        static::assertNotNull($table->getColumn('updated_at'));
        static::assertNotNull($table->getIndex('idx_widgets_name'));
        static::assertNotNull($table->getForeignKey('fk_widgets_user'));
        static::assertSame(['draft', 'published'], $table->getColumn('status')?->allowed);
        static::assertTrue((bool) $table->getColumn('name_lower')?->generated);
        static::assertSame('lower(`name`)', $table->getColumn('name_lower')?->generatedExpression);
        static::assertFalse((bool) $table->getColumn('name_lower')?->generatedStored);
    }
}
