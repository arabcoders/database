<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Migration\ReplayPdo;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SchemaBlueprintMigrationTest extends TestCase
{
    public function testMigrationWithoutChange(): void
    {
        $migration = new class extends SchemaBlueprintMigration {};

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement change()');
        $migration(new Connection(new ReplayPdo(), new SqliteDialect()), new Blueprint());
    }
}
