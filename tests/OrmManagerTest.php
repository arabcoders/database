<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Orm\OrmManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\fixtures\UserEntity;

final class OrmManagerTest extends TestCase
{
    public function testOrmManagerCachesRepositories(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, new SqliteDialect());
        $manager = OrmManager::fromConnection($connection);

        $firstRepo = $manager->repository(UserEntity::class);
        $secondRepo = $manager->repository(UserEntity::class);

        static::assertSame($firstRepo, $secondRepo);
    }

    public function testOrmManagerClearResetsCache(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, new SqliteDialect());
        $manager = OrmManager::fromConnection($connection);

        $repo = $manager->repository(UserEntity::class);

        $manager->clear();

        static::assertNotSame($repo, $manager->repository(UserEntity::class));
    }

    public function testOrmManagerResolvesRepositoriesByConnectionName(): void
    {
        $defaultPdo = new PDO('sqlite::memory:');
        $analyticsPdo = new PDO('sqlite::memory:');

        $defaultConnection = new Connection($defaultPdo, new SqliteDialect());
        $analyticsConnection = new Connection($analyticsPdo, new SqliteDialect());

        $connections = new ConnectionManager();
        $connections->register('default', $defaultConnection);
        $connections->register('analytics', $analyticsConnection);

        $manager = new OrmManager($connections);

        $defaultRepo = $manager->repository(UserEntity::class);
        $analyticsRepo = $manager->repository(UserEntity::class, 'analytics');

        static::assertNotSame($defaultRepo, $analyticsRepo);
        static::assertSame($defaultConnection, $defaultRepo->connection());
        static::assertSame($analyticsConnection, $analyticsRepo->connection());
    }

    public function testOrmManagerUsingConnectionSwitchesDefaultRepositoryConnection(): void
    {
        $defaultPdo = new PDO('sqlite::memory:');
        $reportingPdo = new PDO('sqlite::memory:');

        $defaultConnection = new Connection($defaultPdo, new SqliteDialect());
        $reportingConnection = new Connection($reportingPdo, new SqliteDialect());

        $connections = new ConnectionManager();
        $connections->register('default', $defaultConnection);
        $connections->register('reporting', $reportingConnection);

        $manager = new OrmManager($connections);
        $reportingManager = $manager->usingConnection('reporting');

        $repo = $reportingManager->repository(UserEntity::class);
        static::assertSame($reportingConnection, $repo->connection());
    }

    public function testOrmManagerNamedConnectionRequiresManager(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, new SqliteDialect());
        $manager = OrmManager::fromConnection($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown connection: analytics');
        $manager->repository(UserEntity::class, 'analytics');
    }

    public function testOrmManagerDefaultResolutionTracksConnectionManagerDefault(): void
    {
        $defaultPdo = new PDO('sqlite::memory:');
        $analyticsPdo = new PDO('sqlite::memory:');

        $defaultConnection = new Connection($defaultPdo, new SqliteDialect());
        $analyticsConnection = new Connection($analyticsPdo, new SqliteDialect());

        $connections = new ConnectionManager();
        $connections->register('default', $defaultConnection);
        $connections->register('analytics', $analyticsConnection);

        $manager = new OrmManager($connections);

        static::assertSame('default', $manager->defaultConnectionName());
        static::assertSame($defaultConnection, $manager->connection());

        $connections->setDefault('analytics');

        static::assertSame('analytics', $manager->defaultConnectionName());
        static::assertSame($analyticsConnection, $manager->connection());
        static::assertSame($analyticsConnection, $manager->repository(UserEntity::class)->connection());
    }
}
