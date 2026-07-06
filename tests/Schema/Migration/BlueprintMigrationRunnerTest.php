<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\DatabaseException;
use arabcoders\database\Schema\Migration\BlueprintMigrationRunner;
use arabcoders\database\Schema\Migration\MigrationChecksumMismatchException;
use arabcoders\database\Schema\Migration\MigrationLockException;
use arabcoders\database\Schema\Migration\MigrationRegistry;
use PDO;
use PDOStatement;
use ReflectionClass;
use tests\fixtures\FailingPdo;
use tests\fixtures\FakeMysqlAutoCommitPdo;
use tests\fixtures\Schema\BrokenMigration\TestBrokenIndexMigration;
use tests\fixtures\Schema\Migration\TestWidgetsMigration;
use tests\TestCase;

final class BlueprintMigrationRunnerTest extends TestCase
{
    public function testRunnerAppliesUpAndDown(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->migrate('up');
        static::assertTrue($this->tableExists($pdo, 'widgets'));

        $runner->migrate('down');
        static::assertFalse($this->tableExists($pdo, 'widgets'));
    }

    public function testRunnerRejectsInvalidDirection(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $this->expectException(\RuntimeException::class);
        $runner->migrate('sideways');
    }

    public function testRunnerCreatesPostgresVersionTable(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('pgsql');

        $execSql = [];
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execSql): int {
            $execSql[] = $sql;
            return 0;
        });

        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);

        $queryStmt = $this->createStub(PDOStatement::class);
        $queryStmt->method('fetchAll')->willReturn([]);
        $pdo->method('query')->willReturn($queryStmt);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->listMigrations();
        $matched = array_any($execSql, fn($sql) => str_contains((string) $sql, 'BIGSERIAL') && str_contains((string) $sql, 'TIMESTAMPTZ'));

        static::assertTrue($matched);
    }

    public function testRunnerFailsOnChecksumMismatch(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->migrate('up', false);
        $pdo->exec("UPDATE migration_version SET checksum = 'invalid-checksum' WHERE version = '1'");

        $this->expectException(MigrationChecksumMismatchException::class);
        $runner->migrate('up', true);
    }

    public function testRunnerRepairsChecksum(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->migrate('up', false);
        $pdo->exec("UPDATE migration_version SET checksum = 'invalid-checksum' WHERE version = '1'");

        $runner->migrate('up', false, 0, false, true);

        $migrations = $runner->listMigrations();

        static::assertCount(1, $migrations);
        static::assertTrue((bool) $migrations[0]['applied']);
        static::assertTrue((bool) $migrations[0]['checksum_matches']);
        static::assertNull($migrations[0]['error']);
    }

    public function testRunnerFailsWhenLockIsHeld(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->listMigrations();
        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'other-runner', 1)");

        $this->expectException(MigrationLockException::class);
        $runner->migrate('up', false);
    }

    public function testProbeShowsPendingWithoutMetadataTables(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $result = $runner->probe('up');

        static::assertSame('up', $result['direction']);
        static::assertTrue($result['needed']);
        static::assertCount(1, $result['migrations']);
        static::assertFalse((bool) $result['lock']['locked']);
        static::assertSame([], $result['issues']);
        static::assertFalse($this->tableExists($pdo, 'migration_version'));
        static::assertFalse($this->tableExists($pdo, 'migration_lock'));
    }

    public function testProbeShowsCurrentLockWithoutVersionTable(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $pdo->exec('CREATE TABLE migration_lock (lock_key TEXT PRIMARY KEY, holder TEXT NOT NULL, acquired_at INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'other-runner', 1)");

        $result = $runner->probe('up');

        static::assertTrue((bool) $result['lock']['locked']);
        static::assertSame('other-runner', $result['lock']['holder']);
        static::assertSame(1, $result['lock']['acquired_at']);
        static::assertFalse($this->tableExists($pdo, 'migration_version'));
    }

    public function testRunnerWrapsMetadataQueryErrors(): void
    {
        $pdo = $this->memoryPdo(FailingPdo::class);
        $registry = new MigrationRegistry([$this->migrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        try {
            $runner->lockInfo();
            static::fail('Expected DatabaseException to be thrown.');
        } catch (DatabaseException $exception) {
            static::assertSame(
                'SELECT holder, acquired_at FROM migration_lock WHERE lock_key = :lock_key LIMIT 1',
                $exception->getQueryString(),
            );
            static::assertSame([], $exception->getQueryBind());
        }
    }

    public function testMysqlFailedMigrationCleansUpCommittedSchemaChanges(): void
    {
        $pdo = new FakeMysqlAutoCommitPdo();
        $registry = new MigrationRegistry([$this->brokenIndexMigrationFixturePath()]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('1071 Specified key was too long');

        try {
            $runner->migrate('up', false, 1);
        } finally {
            static::assertFalse($this->tableExists($pdo, 'broken_widgets'));

            $stmt = $pdo->query("SELECT COUNT(*) FROM migration_version WHERE version = '1'");
            static::assertSame('0', (string) $stmt->fetchColumn());
        }
    }

    private function tableExists(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
        $stmt->execute(['name' => $name]);

        return false !== $stmt->fetchColumn();
    }

    private function migrationFixturePath(): string
    {
        $reflection = new ReflectionClass(TestWidgetsMigration::class);

        return dirname((string) $reflection->getFileName());
    }

    private function brokenIndexMigrationFixturePath(): string
    {
        $reflection = new ReflectionClass(TestBrokenIndexMigration::class);

        return dirname((string) $reflection->getFileName());
    }
}
