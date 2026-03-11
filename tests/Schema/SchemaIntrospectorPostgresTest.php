<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\SchemaIntrospector;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaIntrospectorPostgresTest extends TestCase
{
    public function testPostgresIntrospectionPreservesExpressionIndexWithoutColumns(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $schemaStmt = $this->createStub(PDOStatement::class);
        $schemaStmt->method('fetchColumn')->willReturn('public');

        $queryStmt = $this->createStub(PDOStatement::class);
        $queryStmt->method('fetchAll')->willReturn([]);

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($schemaStmt, $queryStmt) {
            if (str_contains($sql, 'current_schema()')) {
                return $schemaStmt;
            }

            return $queryStmt;
        });

        $tablesStmt = $this->mockStmt([
            ['table_name' => 'users'],
        ]);

        $columnsStmt = $this->mockStmt([
            [
                'column_name' => 'id',
                'data_type' => 'integer',
                'udt_name' => 'int4',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'datetime_precision' => null,
                'is_nullable' => 'NO',
                'column_default' => null,
                'is_identity' => 'NO',
                'collation_name' => null,
                'is_generated' => 'NEVER',
                'generation_expression' => null,
            ],
            [
                'column_name' => 'email',
                'data_type' => 'character varying',
                'udt_name' => 'varchar',
                'character_maximum_length' => 255,
                'numeric_precision' => null,
                'numeric_scale' => null,
                'datetime_precision' => null,
                'is_nullable' => 'NO',
                'column_default' => null,
                'is_identity' => 'NO',
                'collation_name' => null,
                'is_generated' => 'NEVER',
                'generation_expression' => null,
            ],
        ]);

        $primaryStmt = $this->mockStmt([
            ['column_name' => 'id', 'ordinal_position' => 1],
        ]);

        $indexesStmt = $this->mockStmt([
            [
                'index_name' => 'idx_users_lower_email',
                'is_unique' => false,
                'index_type' => 'btree',
                'seq' => 1,
                'column_name' => null,
                'index_where' => 'deleted_at IS NULL',
                'index_expression' => '(lower(email))',
            ],
        ]);

        $exprIndexesStmt = $this->mockStmt([]);
        $foreignStmt = $this->mockStmt([]);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (
                $tablesStmt,
                $columnsStmt,
                $primaryStmt,
                $indexesStmt,
                $exprIndexesStmt,
                $foreignStmt,
            ) {
                if (str_contains($sql, 'information_schema.tables')) {
                    return $tablesStmt;
                }
                if (str_contains($sql, 'information_schema.columns')) {
                    return $columnsStmt;
                }
                if (str_contains($sql, "constraint_type = 'PRIMARY KEY'")) {
                    return $primaryStmt;
                }
                if (str_contains($sql, 'pg_get_expr(idx.indpred')) {
                    return $indexesStmt;
                }
                if (str_contains($sql, 'pg_get_indexdef')) {
                    return $exprIndexesStmt;
                }
                if (str_contains($sql, "constraint_type = 'FOREIGN KEY'")) {
                    return $foreignStmt;
                }

                throw new RuntimeException('Unexpected SQL: ' . $sql);
            },
        );

        $schema = (new SchemaIntrospector($pdo))->introspect();
        $table = $schema->getTable('users');

        static::assertNotNull($table);
        $index = $table->getIndex('idx_users_lower_email');
        static::assertNotNull($index);
        static::assertSame([], $index->columns);
        static::assertSame('(lower(email))', $index->expression);
        static::assertSame('deleted_at IS NULL', $index->where);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function mockStmt(array $rows): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        return $stmt;
    }
}
