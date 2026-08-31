<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Commands\MigrationRequest;
use arabcoders\database\Commands\MigrationService;
use arabcoders\database\DatabaseException;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\BlueprintMigrationRunner;
use arabcoders\database\Schema\Migration\MigrationChecksumMismatchException;
use arabcoders\database\Schema\Migration\MigrationLockException;
use arabcoders\database\Schema\Migration\MigrationOrderException;
use arabcoders\database\Schema\Migration\MigrationRegistry;
use arabcoders\database\Schema\Migration\SchemaBlueprintRunner;
use PDO;
use PDOStatement;
use tests\fixtures\FailingPdo;
use tests\fixtures\FakeMysqlAutoCommitPdo;
use tests\fixtures\Schema\BrokenMigration\TestBrokenIndexMigration;
use tests\fixtures\Schema\HistoricalReplay\AddPostTitleMigration;
use tests\fixtures\Schema\HistoricalReplay\CreateAccountsAndPostsMigration;
use tests\fixtures\Schema\Migration\TestWidgetsMigration;
use tests\fixtures\Schema\SequentialPending\AddWidgetNameMigration;
use tests\fixtures\Schema\SequentialPending\CreateWidgetsMigration;
use tests\fixtures\Schema\Squashed\AddWidgetStatusMigration;
use tests\fixtures\Schema\Squashed\SquashedWidgetsMigration;
use tests\Support\DatabaseTestCase;

final class BlueprintMigrationRunnerTest extends DatabaseTestCase
{
    public function testRunnerAppliesBoth(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->migrate('up');
        static::assertTrue($this->sqliteTableExists($pdo, 'widgets'));

        $runner->migrate('down');
        static::assertFalse($this->sqliteTableExists($pdo, 'widgets'));
    }

    public function testRunnerRejectsInvalid(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $this->expectException(\RuntimeException::class);
        $runner->migrate('sideways');
    }

    public function testRunnerCreatesPostgres(): void
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

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->listMigrations();
        $matched = array_any($execSql, fn($sql) => str_contains((string) $sql, 'BIGSERIAL') && str_contains((string) $sql, 'TIMESTAMPTZ'));

        static::assertTrue($matched);
    }

    public function testFailsChecksumMismatch(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
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

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
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

    public function testFailsWhenLocked(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $runner->listMigrations();
        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'other-runner', 1)");

        $this->expectException(MigrationLockException::class);
        $runner->migrate('up', false);
    }

    public function testProbeShowsPending(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $result = $runner->probe('up');

        static::assertSame('up', $result['direction']);
        static::assertTrue($result['needed']);
        static::assertCount(1, $result['migrations']);
        static::assertFalse((bool) $result['lock']['locked']);
        static::assertSame([], $result['issues']);
        static::assertFalse($this->sqliteTableExists($pdo, 'migration_version'));
        static::assertFalse($this->sqliteTableExists($pdo, 'migration_lock'));
    }

    public function testProbeShowsCurrent(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $pdo->exec('CREATE TABLE migration_lock (lock_key TEXT PRIMARY KEY, holder TEXT NOT NULL, acquired_at INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migration_lock (lock_key, holder, acquired_at) VALUES ('schema_migration', 'other-runner', 1)");

        $result = $runner->probe('up');

        static::assertTrue((bool) $result['lock']['locked']);
        static::assertSame('other-runner', $result['lock']['holder']);
        static::assertSame(1, $result['lock']['acquired_at']);
        static::assertFalse($this->sqliteTableExists($pdo, 'migration_version'));
    }

    public function testRunnerWrapsMetadata(): void
    {
        $pdo = $this->memoryPdo(FailingPdo::class);
        $registry = new MigrationRegistry([$this->fixturePath(TestWidgetsMigration::class)]);
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

    public function testMysqlFailedMigration(): void
    {
        $pdo = new FakeMysqlAutoCommitPdo();
        $registry = new MigrationRegistry([$this->fixturePath(TestBrokenIndexMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('1071 Specified key was too long');

        try {
            $runner->migrate('up', false, 1);
        } finally {
            static::assertFalse($this->sqliteTableExists($pdo, 'broken_widgets'));

            $stmt = $pdo->query("SELECT COUNT(*) FROM migration_version WHERE version = '1'");
            static::assertSame('0', (string) $stmt->fetchColumn());
        }
    }

    public function testHistoricalReplaySchema(): void
    {
        $pdo = $this->memoryPdo();
        $registry = new MigrationRegistry([$this->fixturePath(CreateAccountsAndPostsMigration::class)]);
        $runner = new BlueprintMigrationRunner($pdo, $registry);

        $pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL)');
        $runner->markAppliedUpTo('2');
        $runner->migrate('up');

        static::assertTrue($this->sqliteTableExists($pdo, 'accounts'));
        static::assertTrue($this->sqliteTableExists($pdo, 'posts'));
        static::assertTrue($this->sqliteColumnExists($pdo, 'posts', 'title'));
        static::assertFalse($this->sqliteColumnExists($pdo, 'accounts', 'email'));
    }

    public function testMigrationOrder(): void
    {
        $pdo = $this->memoryPdo();
        $service = new MigrationService($pdo, $this->fixturePath(CreateWidgetsMigration::class));

        $pendingUp = $service->migrate(new MigrationRequest(direction: 'up', dryRun: true));
        $upSql = $service->buildDryRunSql('up', $pendingUp->migrations);
        static::assertCount(2, $upSql);

        $isolated = $this->memoryPdo();
        foreach ($upSql as $migration) {
            foreach ($migration['statements'] as $statement) {
                $isolated->exec($statement);
            }
        }
        static::assertTrue($this->sqliteTableExists($isolated, 'pending_widgets'));
        static::assertTrue($this->sqliteColumnExists($isolated, 'pending_widgets', 'name'));

        $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));

        $pending = $service->migrate(new MigrationRequest(direction: 'down', dryRun: true, steps: 2));
        $sql = $service->buildDryRunSql('down', $pending->migrations);
        static::assertCount(2, $sql);

        foreach ($sql as $migration) {
            foreach ($migration['statements'] as $statement) {
                $isolated->exec($statement);
            }
        }
        static::assertFalse($this->sqliteTableExists($isolated, 'pending_widgets'));

        $service->migrate(new MigrationRequest(direction: 'down', dryRun: false, steps: 2));
        static::assertFalse($this->sqliteTableExists($pdo, 'pending_widgets'));
    }

    public function testMysqlHistoricalReplay(): void
    {
        $pdo = new FakeMysqlAutoCommitPdo();
        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(CreateAccountsAndPostsMigration::class)]),
        );

        $diffs = $runner->historicalDiffs();

        static::assertArrayHasKey('3', $diffs);
        static::assertSame([], $pdo->executed);
    }

    public function testProvidedHistoricalState(): void
    {
        $pdo = new FakeMysqlAutoCommitPdo();
        $migration = new AddPostTitleMigration();
        $before = new SchemaDefinition();
        $posts = new TableDefinition('posts');
        $posts->addColumn(new ColumnDefinition('id', ColumnType::Int));
        $before->addTable($posts);
        $pdo->exec('CREATE TABLE posts (id INTEGER)');

        new SchemaBlueprintRunner($pdo)->run($migration, 'up', $before);

        static::assertTrue($this->sqliteColumnExists($pdo, 'posts', 'title'));
        static::assertFalse(array_any($pdo->executed, static fn(string $sql): bool => str_contains($sql, 'information_schema')));
    }

    public function testSquashFreshHistory(): void
    {
        $pdo = $this->memoryPdo();
        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(SquashedWidgetsMigration::class)]),
        );

        $ran = $runner->migrate('up');

        static::assertSame(['2', '3'], array_column($ran, 'id'));
        static::assertTrue($this->sqliteColumnExists($pdo, 'pending_widgets', 'name'));
        static::assertTrue($this->sqliteColumnExists($pdo, 'pending_widgets', 'status'));
    }

    public function testSquashExistingHistory(): void
    {
        $pdo = $this->memoryPdo();
        $historical = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(CreateWidgetsMigration::class)]),
        );
        $historical->migrate('up');

        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(SquashedWidgetsMigration::class)]),
        );
        $probe = $runner->probe('up');

        static::assertSame([], $probe['issues']);
        static::assertSame(['3'], array_column($probe['migrations'], 'id'));

        $ran = $runner->migrate('up');
        static::assertSame(['3'], array_column($ran, 'id'));
        static::assertTrue($this->sqliteColumnExists($pdo, 'pending_widgets', 'status'));

        $listed = $runner->listMigrations();
        static::assertSame(['2', '3'], array_column($listed, 'id'));
        static::assertTrue((bool) $listed[0]['checksum_matches']);
        static::assertSame($listed[0]['checksum'], $listed[0]['applied_checksum']);
        static::assertNull($listed[0]['error']);
    }

    public function testSquashDetectsDrift(): void
    {
        $pdo = $this->memoryPdo();
        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(SquashedWidgetsMigration::class)]),
        );
        $runner->migrate('up');
        $pdo->exec("UPDATE migration_version SET checksum = 'invalid-checksum' WHERE version = '2'");

        $this->expectException(MigrationChecksumMismatchException::class);
        $runner->migrate('up');
    }

    public function testSquashRejectsPartial(): void
    {
        $pdo = $this->memoryPdo();
        $historical = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(AddWidgetNameMigration::class)]),
        );
        $historical->migrate('up', false, 1);

        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(SquashedWidgetsMigration::class)]),
        );

        $this->expectException(MigrationOrderException::class);
        $this->expectExceptionMessage('Migration state is inside squashed range 1 through 2.');
        $runner->migrate('up');
    }

    public function testSquashBlocksRollback(): void
    {
        $pdo = $this->memoryPdo();
        $runner = new BlueprintMigrationRunner(
            $pdo,
            new MigrationRegistry([$this->fixturePath(AddWidgetStatusMigration::class)]),
        );
        $runner->migrate('up');

        try {
            $runner->migrate('down', false, 2);
            static::fail('Expected the squash rollback boundary to be enforced.');
        } catch (MigrationOrderException $exception) {
            static::assertSame('Cannot roll back through squashed migration version 2.', $exception->getMessage());
        }
        static::assertTrue($this->sqliteColumnExists($pdo, 'pending_widgets', 'status'));

        $rolledBack = $runner->migrate('down');
        static::assertSame(['3'], array_column($rolledBack, 'id'));
        static::assertFalse($this->sqliteColumnExists($pdo, 'pending_widgets', 'status'));

        $probe = $runner->probe('down');
        static::assertSame(['Cannot roll back through squashed migration version 2.'], $probe['issues']);

        $this->expectException(MigrationOrderException::class);
        $this->expectExceptionMessage('Cannot roll back through squashed migration version 2.');
        $runner->migrate('down');
    }
}
