<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use PDO;
use RuntimeException;
use Throwable;

final class SeederExecutor
{
    private SeederExecutionHistory $history;

    public function __construct(
        private PDO $pdo,
        ?SeederExecutionHistory $history = null,
    ) {
        $this->history = $history ?? new SeederExecutionHistory($pdo);
    }

    /**
     * Run the operation and return execution results.
     * @param string $class Class.
     * @return void
     * @throws RuntimeException
     */

    public function run(string $class): void
    {
        $instance = new $class();
        if (!$instance instanceof SeederRunner) {
            throw new RuntimeException(sprintf('Seeder %s must extend %s.', $class, SeederRunner::class));
        }

        $connection = new Connection($this->pdo, DialectFactory::fromPdo($this->pdo));
        $instance($connection);
    }

    public function execute(
        SeederDefinition $definition,
        string $mode,
        string $transactionMode = SeederTransactionMode::PER_SEEDER,
    ): SeederExecutionEntry {
        $mode = SeederRunMode::normalize($mode, false);
        $transactionMode = SeederTransactionMode::normalize($transactionMode);
        $this->history->ensureTable();

        if (SeederRunMode::ONCE === $mode && $this->history->hasSuccessfulRun($definition->name)) {
            return new SeederExecutionEntry(
                definition: $definition,
                status: SeederExecutionStatus::SKIPPED,
                reason: 'already executed',
            );
        }

        if (SeederRunMode::REBUILD === $mode) {
            $this->history->deleteBySeederName($definition->name);
        }

        $historyId = null;
        try {
            if (SeederTransactionMode::PER_SEEDER === $transactionMode) {
                $this->pdo->beginTransaction();
            }

            $this->run($definition->class);

            $historyId = $this->history->insert($definition, SeederExecutionStatus::EXECUTED, $mode);

            if (SeederTransactionMode::PER_SEEDER === $transactionMode && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (SeederTransactionMode::PER_SEEDER === $transactionMode && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->history->insert($definition, SeederExecutionStatus::FAILED, $mode, $exception->getMessage());
            throw $exception;
        }

        return new SeederExecutionEntry(
            definition: $definition,
            status: SeederExecutionStatus::EXECUTED,
            historyId: $historyId,
        );
    }

    public function history(): SeederExecutionHistory
    {
        return $this->history;
    }
}
