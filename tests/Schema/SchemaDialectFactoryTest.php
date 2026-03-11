<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Dialect\MysqlDialect;
use arabcoders\database\Schema\Dialect\PostgresDialect;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\Dialect\SqliteDialect;
use arabcoders\database\Dialect\SqliteDialect as QuerySqliteDialect;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaDialectFactoryTest extends TestCase
{
    public function testFactoryResolvesSqliteDialect(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryRejectsUnsupportedDriver(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlsrv');

        $this->expectException(RuntimeException::class);
        SchemaDialectFactory::fromPdo($pdo);
    }

    public function testFactoryResolvesMysqlDialect(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(MysqlDialect::class, $dialect);
    }

    public function testFactoryResolvesPostgresDialect(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        static::assertInstanceOf(PostgresDialect::class, $dialect);
    }

    public function testFactoryResolvesFromDriverName(): void
    {
        $dialect = SchemaDialectFactory::fromDriverName('sqlite');
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryResolvesFromTargetSchemaClass(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(SqliteDialect::class);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryResolvesFromTargetDatabaseClass(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(QuerySqliteDialect::class);
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryResolvesFromTargetInstance(): void
    {
        $dialect = SchemaDialectFactory::fromTarget(new SqliteDialect());
        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }
}
