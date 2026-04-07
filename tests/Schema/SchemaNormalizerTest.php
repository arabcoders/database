<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Dialect\MysqlDialect as DatabaseMysqlDialect;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Dialect\PostgresDialect;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Schema\SchemaNormalizer;
use PHPUnit\Framework\TestCase;

final class SchemaNormalizerTest extends TestCase
{
    public function testNormalizeMysqlRemovesIntegerLength(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('metrics_host_metrics_1m');
        $table->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $table->addColumn(new ColumnDefinition('ts', ColumnType::Int, length: 11));
        $table->addColumn(new ColumnDefinition('mem_used', ColumnType::Int, length: 11, nullable: true));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new MysqlDialect());

        $normalizedTable = $normalized->getTable('metrics_host_metrics_1m');
        static::assertNotNull($normalizedTable);

        $id = $normalizedTable->getColumn('id');
        $ts = $normalizedTable->getColumn('ts');
        $memUsed = $normalizedTable->getColumn('mem_used');

        static::assertNotNull($id);
        static::assertNotNull($ts);
        static::assertNotNull($memUsed);
        static::assertNull($id->length);
        static::assertNull($ts->length);
        static::assertNull($memUsed->length);
    }

    public function testNormalizePostgresRemovesIntegerLength(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $table->addColumn(new ColumnDefinition('user_id', ColumnType::Int, length: 11, nullable: true));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());

        $normalizedTable = $normalized->getTable('widgets');
        static::assertNotNull($normalizedTable);

        $id = $normalizedTable->getColumn('id');
        $userId = $normalizedTable->getColumn('user_id');

        static::assertNotNull($id);
        static::assertNotNull($userId);
        static::assertNull($id->length);
        static::assertNull($userId->length);
    }

    public function testNormalizePostgresConvertsTinyIntToSmallInt(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('test_table');
        $table->addColumn(new ColumnDefinition('tiny_col', ColumnType::TinyInt));
        $table->addColumn(new ColumnDefinition('small_col', ColumnType::SmallInt));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());

        $normalizedTable = $normalized->getTable('test_table');
        static::assertNotNull($normalizedTable);

        $tinyCol = $normalizedTable->getColumn('tiny_col');
        $smallCol = $normalizedTable->getColumn('small_col');

        static::assertNotNull($tinyCol);
        static::assertNotNull($smallCol);
        static::assertSame(ColumnType::SmallInt, $tinyCol->type);
        static::assertSame(ColumnType::SmallInt, $smallCol->type);
    }

    public function testNormalizePostgresConvertsUniqueHashIndexToBtree(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('test_table');
        $table->addIndex(new IndexDefinition(
            'uniq_test_hash',
            ['email'],
            unique: true,
            type: 'index',
            algorithm: ['pgsql' => 'hash'],
        ));
        $table->addIndex(new IndexDefinition(
            'uniq_test_btree',
            ['username'],
            unique: true,
            type: 'index',
            algorithm: ['pgsql' => 'btree'],
        ));
        $table->addIndex(new IndexDefinition(
            'idx_test_hash',
            ['name'],
            unique: false,
            type: 'index',
            algorithm: ['pgsql' => 'hash'],
        ));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());

        $normalizedTable = $normalized->getTable('test_table');
        static::assertNotNull($normalizedTable);

        $uniqueHash = $normalizedTable->getIndex('uniq_test_hash');
        $uniqueBtree = $normalizedTable->getIndex('uniq_test_btree');
        $nonUniqueHash = $normalizedTable->getIndex('idx_test_hash');

        static::assertNotNull($uniqueHash);
        static::assertNotNull($uniqueBtree);
        static::assertNotNull($nonUniqueHash);

        // Unique hash index should be normalized to btree (then btree normalized to empty array)
        static::assertSame([], $uniqueHash->algorithm);
        // Unique btree index gets normalized to empty array (btree is the default)
        static::assertSame([], $uniqueBtree->algorithm);
        // Non-unique hash index stays hash
        static::assertSame(['pgsql' => 'hash'], $nonUniqueHash->algorithm);
    }

    public function testNormalizePostgresConvertsLongTextAndMediumTextToText(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('test_table');
        $table->addColumn(new ColumnDefinition('long_col', ColumnType::LongText));
        $table->addColumn(new ColumnDefinition('medium_col', ColumnType::MediumText));
        $table->addColumn(new ColumnDefinition('text_col', ColumnType::Text));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());

        $normalizedTable = $normalized->getTable('test_table');
        static::assertNotNull($normalizedTable);

        $longCol = $normalizedTable->getColumn('long_col');
        $mediumCol = $normalizedTable->getColumn('medium_col');
        $textCol = $normalizedTable->getColumn('text_col');

        static::assertNotNull($longCol);
        static::assertNotNull($mediumCol);
        static::assertNotNull($textCol);
        static::assertSame(ColumnType::Text, $longCol->type);
        static::assertSame(ColumnType::Text, $mediumCol->type);
        static::assertSame(ColumnType::Text, $textCol->type);
    }

    public function testNormalizePostgresNormalizesAllowedValues(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'status',
            type: ColumnType::Enum,
            allowed: ['draft', 'published'],
        ));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());

        $normalizedTable = $normalized->getTable('widgets');
        static::assertNotNull($normalizedTable);

        $status = $normalizedTable->getColumn('status');
        static::assertNotNull($status);
        static::assertSame(['draft', 'published'], $status->allowed);
    }

    public function testNormalizeMariaDbJsonToLongText(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('test_table');
        $table->addColumn(new ColumnDefinition('payload', ColumnType::Json));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $dialect = new MysqlDialect(new DatabaseMysqlDialect('10.6.0-MariaDB'));
        $normalized = $normalizer->normalize($schema, $dialect);

        $normalizedTable = $normalized->getTable('test_table');
        static::assertNotNull($normalizedTable);

        $payload = $normalizedTable->getColumn('payload');
        static::assertNotNull($payload);
        static::assertSame(ColumnType::LongText, $payload->type);
    }

    public function testNormalizeTrimsAdvancedExpressions(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('widgets');
        $table->addColumn(new ColumnDefinition(
            name: 'score',
            type: ColumnType::Int,
            check: true,
            checkExpression: ' score > 0 ',
            generated: true,
            generatedExpression: ' score + 1 ',
            generatedStored: true,
        ));
        $table->addIndex(new IndexDefinition(
            name: 'idx_widgets_expr',
            columns: [],
            expression: ' (lower(name)) ',
            where: ' deleted_at IS NULL ',
        ));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new PostgresDialect());
        $normalizedTable = $normalized->getTable('widgets');

        static::assertNotNull($normalizedTable);

        $column = $normalizedTable->getColumn('score');
        static::assertNotNull($column);
        static::assertSame('score > 0', $column->checkExpression);
        static::assertSame('score + 1', $column->generatedExpression);

        $index = $normalizedTable->getIndex('idx_widgets_expr');
        static::assertNotNull($index);
        static::assertSame('(lower(name))', $index->expression);
        static::assertSame('deleted_at IS NULL', $index->where);
    }

    public function testNormalizeSqlitePreservesExplicitIndexNames(): void
    {
        $schema = new SchemaDefinition();
        $table = new TableDefinition('widgets');
        $table->addIndex(new IndexDefinition('idx_widgets_runtime_name', ['name']));
        $schema->addTable($table);

        $normalizer = new SchemaNormalizer();
        $normalized = $normalizer->normalize($schema, new SqliteDialect());
        $normalizedTable = $normalized->getTable('widgets');

        static::assertNotNull($normalizedTable);
        static::assertNotNull($normalizedTable->getIndex('idx_widgets_runtime_name'));
    }
}
