<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\MigrationRequest;
use arabcoders\database\Commands\MigrationService;
use PDO;
use tests\fixtures\Schema\Migration\TestWidgetsMigration;
use tests\Support\DatabaseTestCase;

final class MigrationServiceTest extends DatabaseTestCase
{
    public function testListShowsChecksum(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->fixturePath(TestWidgetsMigration::class));

        $initial = $service->list();
        static::assertArrayHasKey('locked', $initial->lock);
        static::assertFalse((bool) $initial->lock['locked']);

        $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));
        $result = $service->list();

        static::assertCount(1, $result->migrations);
        $migration = $result->migrations[0];

        static::assertTrue((bool) $migration['applied']);
        static::assertIsString($migration['checksum']);
        static::assertSame(64, strlen((string) $migration['checksum']));
        static::assertSame($migration['checksum'], $migration['applied_checksum']);
        static::assertTrue((bool) $migration['checksum_matches']);
        static::assertNull($migration['error']);
    }

    public function testListMismatch(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->fixturePath(TestWidgetsMigration::class));
        $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));

        $pdo->exec("UPDATE migration_version SET checksum = 'broken' WHERE version = '1'");

        $result = $service->list();
        static::assertCount(1, $result->migrations);
        static::assertFalse((bool) $result->migrations[0]['checksum_matches']);
        static::assertSame('Stored checksum does not match migration file.', $result->migrations[0]['error']);
    }

    public function testListShowsActive(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->fixturePath(TestWidgetsMigration::class));
        $service->list();

        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'ci-runner', 123)");

        $result = $service->list();
        static::assertTrue((bool) $result->lock['locked']);
        static::assertSame('ci-runner', $result->lock['holder']);
        static::assertSame(123, $result->lock['acquired_at']);
    }

    public function testProbeShowsPending(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->fixturePath(TestWidgetsMigration::class));
        $result = $service->probe(new MigrationRequest(direction: 'up', dryRun: false));

        static::assertSame('up', $result->direction);
        static::assertTrue($result->needed);
        static::assertCount(1, $result->migrations);
        static::assertFalse((bool) $result->lock['locked']);
        static::assertSame([], $result->issues);
        static::assertFalse($this->sqliteTableExists($pdo, 'migration_version'));
        static::assertFalse($this->sqliteTableExists($pdo, 'migration_lock'));
    }

    public function testProbeShowsChecksum(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->fixturePath(TestWidgetsMigration::class));
        $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));

        $checksum = $pdo->query("SELECT checksum FROM migration_version WHERE version = '1'")->fetchColumn();
        static::assertIsString($checksum);
        $pdo->exec("UPDATE migration_version SET checksum = 'broken' WHERE version = '1'");

        $result = $service->probe(new MigrationRequest(direction: 'up', dryRun: false));

        static::assertFalse($result->needed);
        static::assertCount(1, $result->issues);
        static::assertSame(
            'Checksum mismatch for migration version 1. Stored: broken, current: ' . $checksum . '.',
            $result->issues[0],
        );
    }
}
