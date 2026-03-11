<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Query\Identifier;
use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    public function testIdentifierQuotesQualifiedNames(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users"."id"', Identifier::quote($dialect, 'users.id'));
        static::assertSame('*', Identifier::quote($dialect, '*'));
    }

    public function testIdentifierQuotesQualifiedWildcard(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users".*', Identifier::quote($dialect, 'users.*'));
    }

    public function testIdentifierQuotesWithAlias(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users" AS "u"', Identifier::quoteWithAlias($dialect, 'users', 'u'));
        static::assertSame('"users"', Identifier::quoteWithAlias($dialect, 'users', null));
    }
}
