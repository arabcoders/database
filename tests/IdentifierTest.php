<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Query\Identifier;

final class IdentifierTest extends TestCase
{
    public function testIdentifierQuotesQualified(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users"."id"', Identifier::quote($dialect, 'users.id'));
        static::assertSame('*', Identifier::quote($dialect, '*'));
    }

    public function testIdentifierWildcard(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users".*', Identifier::quote($dialect, 'users.*'));
    }

    public function testIdentifierQuotesSegments(): void
    {
        $dialect = new SqliteDialect();

        static::assertSame('"users" AS "u"', Identifier::quoteWithAlias($dialect, 'users', 'u'));
        static::assertSame('"users"', Identifier::quoteWithAlias($dialect, 'users', null));
    }
}
