<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\MigrationRequest;
use arabcoders\database\Commands\MigrationService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tests\fixtures\Schema\Migration\TestWidgetsMigration;

final class MigrationServiceTest extends TestCase
{
    public function testListExposesChecksumAndLockInformation(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->migrationFixturePath());

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

    public function testListShowsChecksumMismatchVisibility(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->migrationFixturePath());
        $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));

        $pdo->exec("UPDATE migration_version SET checksum = 'broken' WHERE version = '1'");

        $result = $service->list();
        static::assertCount(1, $result->migrations);
        static::assertFalse((bool) $result->migrations[0]['checksum_matches']);
        static::assertSame('Stored checksum does not match migration file.', $result->migrations[0]['error']);
    }

    public function testListShowsActiveLockDetails(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $service = new MigrationService($pdo, $this->migrationFixturePath());
        $service->list();

        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'ci-runner', 123)");

        $result = $service->list();
        static::assertTrue((bool) $result->lock['locked']);
        static::assertSame('ci-runner', $result->lock['holder']);
        static::assertSame(123, $result->lock['acquired_at']);
    }

    private function migrationFixturePath(): string
    {
        $reflection = new ReflectionClass(TestWidgetsMigration::class);

        return dirname((string) $reflection->getFileName());
    }
}
