<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Utils\NameHelper;
use PHPUnit\Framework\TestCase;

final class NameHelperTest extends TestCase
{
    public function testIndexNameShortensLongNames(): void
    {
        $columns = [
            'very_long_column_name_0001',
            'very_long_column_name_0002',
            'very_long_column_name_0003',
            'very_long_column_name_0004',
        ];

        $name = NameHelper::indexName(str_repeat('a', 40), $columns, false, 'index');
        static::assertLessThanOrEqual(64, strlen($name));
    }

    public function testForeignKeyNameShortensLongNames(): void
    {
        $name = NameHelper::foreignKeyName(
            str_repeat('b', 40),
            ['very_long_column_name_0001', 'very_long_column_name_0002'],
            str_repeat('c', 40),
        );

        static::assertLessThanOrEqual(64, strlen($name));
    }
}
