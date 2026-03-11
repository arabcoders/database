<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\SeederRequest;
use arabcoders\database\Commands\SeederService;
use arabcoders\database\Seeder\SeederExecutionStatus;
use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederTransactionMode;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeederServiceTest extends TestCase
{
    public function testDependencyOrderingIsDeterministic(): void
    {
        $pdo = $this->memoryPdo();
        $this->createSeedItemsTable($pdo);

        $service = new SeederService($pdo, $this->fixturePath('Graph'));
        $result = $service->run(new SeederRequest(classFilter: 'gamma', dryRun: true));

        static::assertSame(['alpha', 'beta', 'gamma'], $this->entryNames($result->executionEntries()));
        static::assertSame(
            [SeederExecutionStatus::PENDING, SeederExecutionStatus::PENDING, SeederExecutionStatus::PENDING],
            $this->entryStatuses($result->executionEntries()),
        );
    }

    public function testDependencyCyclesAreDetected(): void
    {
        $pdo = $this->memoryPdo();
        $this->createSeedItemsTable($pdo);

        $service = new SeederService($pdo, $this->fixturePath('Cycle'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Seeder dependency cycle detected');

        $service->run(new SeederRequest(dryRun: true));
    }

    public function testRunOnceSkipsUsingExecutionHistory(): void
    {
        $pdo = $this->memoryPdo();
        $this->createSeedItemsTable($pdo);

        $service = new SeederService($pdo, $this->fixturePath('RunModes'));

        $service->run(new SeederRequest(classFilter: 'once_mode', dryRun: false, mode: SeederRunMode::ONCE));
        $second = $service->run(new SeederRequest(classFilter: 'once_mode', dryRun: false, mode: SeederRunMode::ONCE));

        static::assertSame(['once'], $this->fetchLabels($pdo));
        static::assertSame([SeederExecutionStatus::SKIPPED], $this->entryStatuses($second->executionEntries()));
    }

    public function testPerRunTransactionRollsBackOnFailure(): void
    {
        $pdo = $this->memoryPdo();
        $this->createTransactionTable($pdo);

        $service = new SeederService($pdo, $this->fixturePath('Transactions'));

        try {
            $service->run(new SeederRequest(dryRun: false, transactionMode: SeederTransactionMode::PER_RUN));
            static::fail('Expected seeder execution to fail.');
        } catch (RuntimeException $exception) {
            static::assertSame('intentional seeder failure', $exception->getMessage());
        }

        $stmt = $pdo->query('SELECT COUNT(*) FROM tx_items');
        static::assertSame(0, (int) $stmt->fetchColumn());

        $historyCount = (int) $pdo
            ->query("SELECT COUNT(*) FROM seeder_version WHERE seeder_name = 'tx_fail' AND status = 'failed'")
            ->fetchColumn();
        static::assertSame(1, $historyCount);
    }

    public function testDryRunExposesOrderAndSkipStatus(): void
    {
        $pdo = $this->memoryPdo();
        $this->createSeedItemsTable($pdo);

        $service = new SeederService($pdo, $this->fixturePath('RunModes'));
        $service->run(new SeederRequest(classFilter: 'once_mode', dryRun: false, mode: SeederRunMode::ONCE));

        $dryRun = $service->run(new SeederRequest(classFilter: 'once_mode', dryRun: true, mode: SeederRunMode::ONCE));

        static::assertSame(['once_mode'], $this->entryNames($dryRun->executionEntries()));
        static::assertSame([SeederExecutionStatus::SKIPPED], $this->entryStatuses($dryRun->executionEntries()));
    }

    private function memoryPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function createSeedItemsTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE seed_items (label VARCHAR(64) NOT NULL)');
    }

    private function createTransactionTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE tx_items (label VARCHAR(64) NOT NULL)');
    }

    private function fixturePath(string $suffix): string
    {
        return TESTS_PATH . '/fixtures/Seeder/' . $suffix;
    }

    private function fetchLabels(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT label FROM seed_items ORDER BY rowid ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): string => (string) $row['label'], $rows);
    }

    private function entryNames(array $entries): array
    {
        return array_map(static fn($entry): string => $entry->definition->name, $entries);
    }

    private function entryStatuses(array $entries): array
    {
        return array_map(static fn($entry): string => $entry->status, $entries);
    }
}
