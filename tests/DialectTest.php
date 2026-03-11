<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\MysqlDialect;
use arabcoders\database\Dialect\PostgresDialect;
use arabcoders\database\Dialect\SqliteDialect;
use PHPUnit\Framework\TestCase;

final class DialectTest extends TestCase
{
    public function testMysqlDialectQuotingAndLimit(): void
    {
        $dialect = new MysqlDialect();

        static::assertSame('mysql', $dialect->name());
        static::assertSame('`users`', $dialect->quoteIdentifier('users'));
        static::assertSame("'O''Reilly'", $dialect->quoteString("O'Reilly"));
        static::assertSame('LIMIT 10 OFFSET 5', $dialect->renderLimit(10, 5));
        static::assertSame('LIMIT 10', $dialect->renderLimit(10, null));
        static::assertSame('LIMIT 18446744073709551615 OFFSET 3', $dialect->renderLimit(null, 3));
        static::assertSame('', $dialect->renderLimit(null, null));
        static::assertFalse($dialect->supportsReturning());
        static::assertFalse($dialect->supportsUpsertDoNothing());
        static::assertTrue($dialect->supportsWindowFunctions());
        static::assertSame('VALUES(`name`)', $dialect->renderUpsertInsertValue('name'));
    }

    public function testSqliteDialectQuotingAndLimit(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('sqlite', $dialect->name());
        static::assertSame('"users"', $dialect->quoteIdentifier('users'));
        static::assertSame("'O''Reilly'", $dialect->quoteString("O'Reilly"));
        static::assertSame('LIMIT 10 OFFSET 5', $dialect->renderLimit(10, 5));
        static::assertSame('LIMIT 10', $dialect->renderLimit(10, null));
        static::assertSame('LIMIT -1 OFFSET 3', $dialect->renderLimit(null, 3));
        static::assertSame('', $dialect->renderLimit(null, null));
        static::assertTrue($dialect->supportsReturning());
        static::assertTrue($dialect->supportsUpsertDoNothing());
        static::assertTrue($dialect->supportsWindowFunctions());
        static::assertSame('excluded."name"', $dialect->renderUpsertInsertValue('name'));
    }

    public function testPostgresDialectQuotingAndLimit(): void
    {
        $dialect = new PostgresDialect();

        static::assertSame('pgsql', $dialect->name());
        static::assertSame('"users"', $dialect->quoteIdentifier('users'));
        static::assertSame("'O''Reilly'", $dialect->quoteString("O'Reilly"));
        static::assertSame('LIMIT 10 OFFSET 5', $dialect->renderLimit(10, 5));
        static::assertSame('LIMIT 10', $dialect->renderLimit(10, null));
        static::assertSame('OFFSET 3', $dialect->renderLimit(null, 3));
        static::assertSame('', $dialect->renderLimit(null, null));
        static::assertTrue($dialect->supportsReturning());
        static::assertTrue($dialect->supportsUpsertDoNothing());
        static::assertTrue($dialect->supportsWindowFunctions());
        static::assertSame('EXCLUDED."name"', $dialect->renderUpsertInsertValue('name'));
    }

    public function testMysqlDialectSupportsReturningForVersion(): void
    {
        $dialect = new MysqlDialect('8.0.21');

        static::assertTrue($dialect->supportsReturning());
    }
}
