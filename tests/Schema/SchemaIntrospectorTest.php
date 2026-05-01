<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\DatabaseException;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Schema\SchemaDiffer;
use arabcoders\database\Schema\SchemaIntrospectOptions;
use arabcoders\database\Schema\SchemaIntrospector;
use arabcoders\database\Schema\SchemaNormalizer;
use arabcoders\database\Schema\Utils\NameHelper;
use PDO;
use PDOException;
use PDOStatement;
use tests\TestCase;

final class SchemaIntrospectorTest extends TestCase
{
    public function testBuildsSchema(): void
    {
        $pdo = $this->memoryPdo();
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

    public function testSqliteRoundTripKeepsAdvancedIndexesStable(): void
    {
        $pdo = $this->memoryPdo();
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

        $partial = $table->getIndex('idx_widgets_partial');
        static::assertNotNull($partial);
        static::assertSame('deleted_at IS NULL', $partial->where);

        $expression = $table->getIndex('idx_widgets_expr');
        static::assertNotNull($expression);
        static::assertSame('(lower(name))', $expression->expression);
    }

    public function testIgnoresIndexesWithOptions(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX idx_widgets_name ON widgets(name)');
        $pdo->exec('CREATE INDEX idx_widgets_expr ON widgets((lower(name)))');

        $schema = new SchemaIntrospector($pdo)->introspect(new SchemaIntrospectOptions(
            ignoreIndex: static fn(string $table, IndexDefinition $index): bool => 'widgets' === $table && null !== $index->expression,
        ));
        $table = $schema->getTable('widgets');

        static::assertNotNull($table);
        static::assertNotNull($table->getIndex('idx_widgets_name'));
        static::assertNull($table->getIndex('idx_widgets_expr'));
    }

    public function testWrapsSqliteIntrospectionErrorsWithQuery(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlite');

        $tablesStmt = $this->createStub(PDOStatement::class);
        $tablesStmt
            ->method('fetchAll')
            ->willReturn([
                ['name' => 'widgets', 'sql' => 'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            ]);

        $columnsStmt = $this->createStub(PDOStatement::class);
        $columnsStmt
            ->method('fetchAll')
            ->willReturn([
                [
                    'name' => 'id',
                    'type' => 'INTEGER',
                    'notnull' => 1,
                    'dflt_value' => null,
                    'pk' => 1,
                ],
                [
                    'name' => 'name',
                    'type' => 'TEXT',
                    'notnull' => 1,
                    'dflt_value' => null,
                    'pk' => 0,
                ],
            ]);

        $indexStmt = $this->createStub(PDOStatement::class);
        $indexStmt
            ->method('fetchAll')
            ->willReturn([
                ['origin' => 'c', 'name' => 'idx_widgets_broken', 'unique' => 0, 'partial' => 0],
            ]);

        $indexSqlStmt = $this->createStub(PDOStatement::class);
        $indexSqlStmt->method('fetch')->willReturn(['sql' => 'CREATE INDEX idx_widgets_broken ON widgets(name)']);

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($tablesStmt, $columnsStmt, $indexStmt, $indexSqlStmt) {
            if (str_contains($sql, 'sqlite_master') && str_contains($sql, "type='table'")) {
                return $tablesStmt;
            }

            if (str_contains($sql, 'sqlite_master') && str_contains($sql, "type='index'")) {
                return $indexSqlStmt;
            }

            if (str_contains($sql, 'PRAGMA table_xinfo')) {
                return $columnsStmt;
            }

            if (str_contains($sql, 'PRAGMA index_list')) {
                return $indexStmt;
            }

            if (str_contains($sql, 'PRAGMA index_info')) {
                throw new PDOException('broken index metadata');
            }

            throw new \RuntimeException('Unexpected SQL: ' . $sql);
        });

        try {
            new SchemaIntrospector($pdo)->introspect();
            static::fail('Expected DatabaseException to be thrown.');
        } catch (DatabaseException $exception) {
            static::assertSame('PRAGMA index_info("idx_widgets_broken")', $exception->getQueryString());
            static::assertSame([], $exception->getQueryBind());
        }
    }
}
