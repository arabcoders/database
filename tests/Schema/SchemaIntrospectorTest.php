<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Schema\SchemaDiffer;
use arabcoders\database\Schema\SchemaIntrospector;
use arabcoders\database\Schema\SchemaNormalizer;
use arabcoders\database\Schema\Utils\NameHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaIntrospectorTest extends TestCase
{
    public function testSqliteIntrospectionBuildsSchema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $pdo->exec(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, user_id INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
        );
        $pdo->exec('CREATE INDEX idx_widgets_name ON widgets(name)');

        $schema = new SchemaIntrospector($pdo)->introspect();
        $table = $schema->getTable('widgets');

        static::assertNotNull($table);
        static::assertSame(['id'], $table->getPrimaryKey());

        $idColumn = $table->getColumn('id');
        static::assertNotNull($idColumn);
        static::assertTrue($idColumn->autoIncrement);

        $index = $table->getIndex('idx_widgets_name');
        static::assertNotNull($index);

        $foreignKey = $table->getForeignKey(NameHelper::foreignKeyName('widgets', ['user_id'], 'users'));
        static::assertNotNull($foreignKey);
    }

    public function testSqliteIntrospectionRoundTripHasStableDiffForAdvancedIndexes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            'CREATE TABLE widgets ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL, '
            . 'deleted_at TEXT NULL, '
            . 'name_lower TEXT GENERATED ALWAYS AS (lower(name)) STORED'
            . ')',
        );
        $pdo->exec('CREATE INDEX idx_widgets_partial ON widgets(name) WHERE deleted_at IS NULL');
        $pdo->exec('CREATE INDEX idx_widgets_expr ON widgets((lower(name)))');

        $introspector = new SchemaIntrospector($pdo);
        $normalizer = new SchemaNormalizer();
        $dialect = new SqliteDialect();

        $schemaA = $normalizer->normalize($introspector->introspect(), $dialect);
        $schemaB = $normalizer->normalize($introspector->introspect(), $dialect);

        $operations = new SchemaDiffer()
            ->diff($schemaA, $schemaB)
            ->getOperations();
        static::assertCount(0, $operations);

        $table = $schemaA->getTable('widgets');
        static::assertNotNull($table);

        $generated = $table->getColumn('name_lower');
        static::assertNotNull($generated);
        static::assertTrue($generated->generated);

        $partial = $table->getIndex(NameHelper::indexName('widgets', ['name'], false, 'index'));
        static::assertNotNull($partial);
        static::assertSame('deleted_at IS NULL', $partial->where);

        $expression = $table->getIndex('idx_widgets_expr');
        static::assertNotNull($expression);
        static::assertSame('(lower(name))', $expression->expression);
    }
}
