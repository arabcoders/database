<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\SqliteDialect;
use PDO;
use RuntimeException;

final class ConnectionManagerTest extends TestCase
{
    public function testManagerRegistersConnections(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection($this->memoryPdo(), new SqliteDialect());
        $analytics = new Connection($this->memoryPdo(), new SqliteDialect());

        $manager->register('default', $default);
        $manager->register('analytics', $analytics);

        static::assertTrue($manager->has('default'));
        static::assertTrue($manager->has('analytics'));
        static::assertSame($default, $manager->get());
        static::assertSame($analytics, $manager->get('analytics'));
    }

    public function testManagerChangesDefault(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection($this->memoryPdo(), new SqliteDialect());
        $reporting = new Connection($this->memoryPdo(), new SqliteDialect());

        $manager->register('default', $default);
        $manager->register('reporting', $reporting);

        $manager->setDefault('reporting');

        static::assertSame('reporting', $manager->defaultName());
        static::assertSame($reporting, $manager->get());
    }

    public function testThrowsForUnknown(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new Connection($this->memoryPdo(), new SqliteDialect()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown connection');
        $manager->get('missing');
    }

    public function testTrimsConnectionNames(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection($this->memoryPdo(), new SqliteDialect());
        $manager->register('default', $default);

        static::assertTrue($manager->has(' default '));
        static::assertSame($default, $manager->get(' default '));
    }
}
