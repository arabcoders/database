<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\SqliteDialect;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionManagerTest extends TestCase
{
    public function testConnectionManagerRegistersAndResolvesConnections(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $analytics = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());

        $manager->register('default', $default);
        $manager->register('analytics', $analytics);

        static::assertTrue($manager->has('default'));
        static::assertTrue($manager->has('analytics'));
        static::assertSame($default, $manager->get());
        static::assertSame($analytics, $manager->get('analytics'));
    }

    public function testConnectionManagerSupportsChangingDefaultConnection(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $reporting = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());

        $manager->register('default', $default);
        $manager->register('reporting', $reporting);

        $manager->setDefault('reporting');

        static::assertSame('reporting', $manager->defaultName());
        static::assertSame($reporting, $manager->get());
    }

    public function testConnectionManagerThrowsForUnknownConnection(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new Connection(new PDO('sqlite::memory:'), new SqliteDialect()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown connection');
        $manager->get('missing');
    }

    public function testConnectionManagerTrimsNamesForLookupMethods(): void
    {
        $manager = new ConnectionManager();
        $default = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $manager->register('default', $default);

        static::assertTrue($manager->has(' default '));
        static::assertSame($default, $manager->get(' default '));
    }
}
