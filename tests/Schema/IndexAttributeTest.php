<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Unique;
use tests\TestCase;

final class IndexAttributeTest extends TestCase
{
    public function testIndexSupportsPredicateAndExpression(): void
    {
        $index = new Index(where: 'deleted_at IS NULL', expression: '(lower(email))');

        static::assertSame('deleted_at IS NULL', $index->where);
        static::assertSame('(lower(email))', $index->expression);
    }

    public function testUniqueSupportsPredicateAndExpression(): void
    {
        $unique = new Unique(where: 'tenant_id IS NOT NULL', expression: '(lower(name))');

        static::assertSame('tenant_id IS NOT NULL', $unique->where);
        static::assertSame('(lower(name))', $unique->expression);
    }
}
