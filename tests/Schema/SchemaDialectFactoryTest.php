<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Dialect\SqliteDialect as QuerySqliteDialect;
use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Dialect\PostgresDialect;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use PDO;
use RuntimeException;
use tests\TestCase;

final class SchemaDialectFactoryTest extends TestCase
{
    public function testFactoryResolvesSqlite(): void
    {
        $pdo = $this->memoryPdo();

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryRejectsUnsupported(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlsrv');

        $this->expectException(RuntimeException::class);
        SchemaDialectFactory::fromPdo($pdo);
    }

    public function testFactoryResolvesMysql(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(MysqlDialect::class, $dialect);
    }

    public function testFactoryResolvesPostgres(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(PostgresDialect::class, $dialect);
    }

    public function testFactoryResolvesDriver(): void
    {
        $dialect = SchemaDialectFactory::fromDriverName('sqlite');
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryFromSchema(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(SqliteDialect::class);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryFromDatabase(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(QuerySqliteDialect::class);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryFromInstance(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(new SqliteDialect());
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }
}
