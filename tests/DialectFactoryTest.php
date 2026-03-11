<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Dialect\MysqlDialect;
use arabcoders\database\Dialect\PostgresDialect;
use arabcoders\database\Dialect\SqliteDialect;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DialectFactoryTest extends TestCase
{
    public function testFactoryResolvesSqliteDialect(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $dialect = DialectFactory::fromPdo($pdo);

        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryResolvesMysqlDialect(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $dialect = DialectFactory::fromPdo($pdo);
        static::assertInstanceOf(MysqlDialect::class, $dialect);
    }

    public function testFactoryResolvesPostgresDialect(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $dialect = DialectFactory::fromPdo($pdo);
        static::assertInstanceOf(PostgresDialect::class, $dialect);
    }

    public function testFactoryRejectsUnknownDriver(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlsrv');

        $this->expectException(RuntimeException::class);
        DialectFactory::fromPdo($pdo);
    }
}
