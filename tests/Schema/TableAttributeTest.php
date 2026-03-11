<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Attributes\Schema\Table;
use PHPUnit\Framework\TestCase;

final class TableAttributeTest extends TestCase
{
    public function testPrevNameAssignsExplicitValue(): void
    {
        $table = new Table(prevName: 'legacy_table');
        static::assertSame('legacy_table', $table->prevName);
    }

    public function testNameAssignsExplicitValue(): void
    {
        $table = new Table(name: 'widgets');
        static::assertSame('widgets', $table->name);
    }
}
