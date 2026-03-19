<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\Migration\BlueprintMigrationRunner;
use arabcoders\database\Schema\Migration\MigrationRegistry;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PDO;

final class MigrationService
{
    public function __construct(
        private PDO $pdo,
        private string $migrationDirectory,
        private string $versionTable = 'migration_version',
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    public function list(): MigrationListResult
    {
        $runner = $this->runner();
        $migrations = $runner->listMigrations();
        $lock = $runner->lockInfo();

        return new MigrationListResult($migrations, $lock);
    }

    public function probe(MigrationRequest $request): MigrationProbeResult
    {
        $probe = $this->runner()->probe(
            $request->direction,
            $request->steps,
            $request->force,
            $request->repair,
        );

        return new MigrationProbeResult(
            $probe['direction'],
            $probe['needed'],
            $probe['migrations'],
            $probe['lock'],
            $probe['issues'],
        );
    }

    public function skipUpTo(
        #[\SensitiveParameter]
        string $token,
        bool $dryRun = false,
        bool $force = false,
        bool $repair = false,
    ): MigrationSkipResult {
        $runner = $this->runner();
        $migrations = $runner->markAppliedUpTo($token, $dryRun, $force, $repair);

        return new MigrationSkipResult($migrations, $dryRun);
    }

    /**
     * Run migrations for the requested direction and return affected migration definitions.
     * @param MigrationRequest $request Request.
     * @return MigrationOperationResult
     */

    public function migrate(MigrationRequest $request): MigrationOperationResult
    {
        $runner = $this->runner();
        $migrations = $runner->migrate(
            $request->direction,
            $request->dryRun,
            $request->steps,
            $request->force,
            $request->repair,
        );

        return new MigrationOperationResult($migrations, $request->dryRun);
    }

    /**
     * Render SQL statements for a migration dry run without mutating migration state.
     *
     * @param string $direction Migration direction used to pick up/down statements.
     * @param array<int,array{id:string,name:string,class:string}> $migrations
     * @return array<int,array{id:string,name:string,statements:array<int,string>}>
     */
    public function buildDryRunSql(string $direction, array $migrations): array
    {
        if (empty($migrations)) {
            return [];
        }

        $direction = strtolower($direction);
        $dialect = SchemaDialectFactory::fromPdo($this->pdo);
        $renderer = new SchemaSqlRenderer($dialect);
        $result = [];

        foreach ($migrations as $definition) {
            $class = $definition['class'];
            $instance = new $class();
            if (!$instance instanceof SchemaBlueprintMigration) {
                continue;
            }

            $connection = new Connection($this->pdo, DialectFactory::fromPdo($this->pdo));
            $blueprint = new Blueprint();
            $instance($connection, $blueprint);

            $diff = $blueprint->toDiff();
            $sql = $renderer->render($diff);
            $statements = 'down' === $direction ? $sql->down : $sql->up;

            if (empty($statements)) {
                continue;
            }

            $result[] = [
                'id' => $definition['id'],
                'name' => $definition['name'],
                'statements' => $statements,
            ];
        }

        return $result;
    }

    private function runner(): BlueprintMigrationRunner
    {
        $registry = new MigrationRegistry([$this->migrationDirectory], $this->container);

        return new BlueprintMigrationRunner($this->pdo, $registry, $this->versionTable);
    }
}
