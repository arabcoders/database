<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Dialect\MysqlDialect;
use arabcoders\database\Dialect\PostgresDialect;
use arabcoders\database\Dialect\SqliteDialect;
use PDO;
use RuntimeException;

final class DialectFactoryTest extends TestCase
{
    public function testFactoryResolvesSqlite(): void
    {
        $pdo = $this->memoryPdo();
        $dialect = DialectFactory::fromPdo($pdo);

        static::assertInstanceOf(SqliteDialect::class, $dialect);
    }

    public function testFactoryResolvesMysql(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $dialect = DialectFactory::fromPdo($pdo);
        static::assertInstanceOf(MysqlDialect::class, $dialect);
    }

    public function testFactoryResolvesPostgres(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $dialect = DialectFactory::fromPdo($pdo);
        static::assertInstanceOf(PostgresDialect::class, $dialect);
    }

    public function testFactoryRejectsUnknown(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('sqlsrv');

        $this->expectException(RuntimeException::class);
        DialectFactory::fromPdo($pdo);
    }
}
