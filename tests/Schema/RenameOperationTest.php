<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use PHPUnit\Framework\TestCase;

final class RenameOperationTest extends TestCase
{
    public function testRenameTableOperationMetadata(): void
    {
        $operation = new RenameTableOperation('legacy', 'current');
        static::assertSame('rename_table', $operation->getType());
        static::assertSame('current', $operation->getTableName());
    }

    public function testRenameColumnOperationMetadata(): void
    {
        $operation = new RenameColumnOperation('widgets', 'fieldFoo', 'field_foo');
        static::assertSame('rename_column', $operation->getType());
        static::assertSame('widgets', $operation->getTableName());
    }
}
