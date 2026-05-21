<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\PdoOperations;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\MigrationSql;
use arabcoders\database\Schema\MigrationSqlStep;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PDO;
use RuntimeException;
use Throwable;

final readonly class SchemaBlueprintRunner
{
    use PdoOperations;

    public function __construct(
        private PDO $pdo,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Run the operation and return execution results.
     * @param SchemaBlueprintMigration $migration Migration.
     * @param string $direction Direction.
     * @return void
     * @throws RuntimeException
     */

    public function run(SchemaBlueprintMigration $migration, string $direction): void
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Only up/down migration path available.');
        }

        $connection = new Connection($this->pdo, DialectFactory::fromPdo($this->pdo));
        $blueprint = new Blueprint();

        $migration($connection, $blueprint);

        $diff = $blueprint->toDiff();
        $renderer = new SchemaSqlRenderer(SchemaDialectFactory::fromPdo($this->pdo));
        $sql = $renderer->render($diff);

        if ($this->shouldCompensateSchemaFailures($direction)) {
            $this->runWithCompensation($sql);
            return;
        }

        $statements = 'up' === $direction ? $sql->up : $sql->down;
        foreach ($statements as $statement) {
            if ('' === trim($statement)) {
                continue;
            }

            $this->pdoExec($statement);
        }
    }

    private function shouldCompensateSchemaFailures(string $direction): bool
    {
        return 'up' === $direction && 'mysql' === (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function runWithCompensation(MigrationSql $sql): void
    {
        $completed = [];

        try {
            foreach ($sql->steps as $step) {
                $executed = false;
                foreach ($step->up as $statement) {
                    if ('' === trim($statement)) {
                        continue;
                    }

                    $this->pdoExec($statement);
                    $executed = true;
                }

                if ($executed) {
                    $completed[] = $step;
                }
            }
        } catch (Throwable $exception) {
            $this->cleanupCompletedSteps($completed, $exception);
            throw $exception;
        }
    }

    /**
     * @param array<int,MigrationSqlStep> $completed
     */
    private function cleanupCompletedSteps(array $completed, Throwable $exception): void
    {
        if ([] === $completed) {
            return;
        }

        $irreversible = array_values(array_filter($completed, static fn(MigrationSqlStep $step): bool => false === $step->reversible));
        if ([] !== $irreversible) {
            $types = implode(', ', array_unique(array_map(static fn(MigrationSqlStep $step): string => $step->type, $irreversible)));
            throw new MigrationCleanupException(
                sprintf(
                    'Migration failed after MySQL auto-committed schema changes for [%s]. Automatic cleanup could not safely restore the previous state. Manual cleanup may be required before retrying.',
                    $types,
                ),
                0,
                $exception,
            );
        }

        $cleanupErrors = [];
        foreach (array_reverse($completed) as $step) {
            foreach ($step->down as $statement) {
                if ('' === trim($statement)) {
                    continue;
                }

                try {
                    $this->pdoExec($statement);
                } catch (Throwable $cleanupException) {
                    $cleanupErrors[] = $cleanupException->getMessage();
                }
            }
        }

        if ([] === $cleanupErrors) {
            return;
        }

        throw new MigrationCleanupException(
            sprintf(
                'Migration failed and automatic cleanup did not complete cleanly. Manual cleanup may be required before retrying. Cleanup errors: %s',
                implode(' | ', array_values(array_unique($cleanupErrors))),
            ),
            0,
            $exception,
        );
    }
}
