<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Seeder\SeederDefinition;
use arabcoders\database\Seeder\SeederDependencyResolver;
use arabcoders\database\Seeder\SeederExecutionEntry;
use arabcoders\database\Seeder\SeederExecutionHistory;
use arabcoders\database\Seeder\SeederExecutionStatus;
use arabcoders\database\Seeder\SeederExecutor;
use arabcoders\database\Seeder\SeederRegistry;
use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederTransactionMode;
use PDO;
use RuntimeException;
use Throwable;

final class SeederService
{
    public function __construct(
        private PDO $pdo,
        private string $seederDirectory,
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    /**
     * @return array<int,SeederDefinition>
     */
    public function list(): array
    {
        $registry = new SeederRegistry([$this->seederDirectory], $this->container);

        return $registry->all();
    }

    public function run(SeederRequest|string $request = '', bool $dryRun = true, ?callable $onRun = null): SeederResult
    {
        $request = $request instanceof SeederRequest
            ? $request
            : new SeederRequest(classFilter: $request, dryRun: $dryRun);

        $runMode = SeederRunMode::normalize($request->mode);
        $transactionMode = SeederTransactionMode::normalize($request->transactionMode);
        $definitions = $this->list();
        if (empty($definitions)) {
            return new SeederResult([], $request->dryRun, []);
        }

        $roots = $this->resolveTargets($definitions, $request->classFilter);
        $roots = $this->filterByTagAndGroup($roots, $request->tag, $request->group);
        if (empty($roots)) {
            return new SeederResult([], $request->dryRun, []);
        }

        $resolver = new SeederDependencyResolver();
        $targets = $resolver->resolve($definitions, array_map(
            static fn(SeederDefinition $definition): string => $definition->name,
            $roots,
        ));

        $history = new SeederExecutionHistory($this->pdo);
        $entries = $this->resolveDryRunEntries($targets, $history, $runMode);

        if ($request->dryRun) {
            return new SeederResult($targets, true, $entries);
        }

        $executor = new SeederExecutor($this->pdo, $history);
        $executor->history()->ensureTable();

        $executedEntries = [];
        $perRun = SeederTransactionMode::PER_RUN === $transactionMode;
        $currentDefinition = null;
        $currentMode = null;

        try {
            if ($perRun) {
                $this->pdo->beginTransaction();
            }

            foreach ($targets as $definition) {
                $effectiveMode = $this->resolveRunMode($definition, $runMode);
                $currentDefinition = $definition;
                $currentMode = $effectiveMode;
                $entry = $executor->execute(
                    $definition,
                    $effectiveMode,
                    $perRun ? SeederTransactionMode::NONE : $transactionMode,
                );

                if (SeederExecutionStatus::EXECUTED === $entry->status && null !== $onRun) {
                    $onRun($definition);
                }

                $executedEntries[] = $entry;
            }

            if ($perRun && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($perRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();

                if ($currentDefinition instanceof SeederDefinition && is_string($currentMode)) {
                    $executor->history()->insert(
                        $currentDefinition,
                        SeederExecutionStatus::FAILED,
                        $currentMode,
                        $exception->getMessage(),
                    );
                }
            }
            throw $exception;
        }

        return new SeederResult($targets, false, $executedEntries);
    }

    /**
     * @param array<int,SeederDefinition> $definitions
     * @return array<int,SeederDefinition>
     */
    private function resolveTargets(array $definitions, string $classOption): array
    {
        $classOption = trim($classOption);
        if ('' === $classOption) {
            return $definitions;
        }

        $matches = [];
        foreach ($definitions as $definition) {
            $name = strtolower($definition->name);
            if (str_starts_with($name, strtolower($classOption))) {
                $matches[] = $definition;
            }
        }

        if (empty($matches)) {
            throw new RuntimeException('No matching seeder found.');
        }

        if (count($matches) > 1) {
            throw new RuntimeException('Multiple seeders match the name. Use a more specific name.');
        }

        return $matches;
    }

    /**
     * @param array<int,SeederDefinition> $definitions
     * @return array<int,SeederDefinition>
     */
    private function filterByTagAndGroup(array $definitions, ?string $tag, ?string $group): array
    {
        $tag = is_string($tag) ? trim($tag) : '';
        $group = is_string($group) ? trim($group) : '';

        if ('' === $tag && '' === $group) {
            return $definitions;
        }

        $matches = [];
        foreach ($definitions as $definition) {
            if ('' !== $tag && !in_array($tag, $definition->tags, true)) {
                continue;
            }

            if ('' !== $group && !in_array($group, $definition->groups, true)) {
                continue;
            }

            $matches[] = $definition;
        }

        return $matches;
    }

    private function resolveRunMode(SeederDefinition $definition, string $requestedMode): string
    {
        if (SeederRunMode::AUTO !== $requestedMode) {
            return $requestedMode;
        }

        return SeederRunMode::normalize($definition->mode, false);
    }

    /**
     * @param array<int,SeederDefinition> $definitions
     * @return array<int,SeederExecutionEntry>
     */
    private function resolveDryRunEntries(array $definitions, SeederExecutionHistory $history, string $requestedMode): array
    {
        $entries = [];
        foreach ($definitions as $definition) {
            $effectiveMode = $this->resolveRunMode($definition, $requestedMode);
            if (SeederRunMode::ONCE === $effectiveMode && $history->hasSuccessfulRun($definition->name)) {
                $entries[] = new SeederExecutionEntry(
                    definition: $definition,
                    status: SeederExecutionStatus::SKIPPED,
                    reason: 'already executed',
                );
                continue;
            }

            $entries[] = new SeederExecutionEntry(
                definition: $definition,
                status: SeederExecutionStatus::PENDING,
                reason: 'would execute (' . $effectiveMode . ')',
            );
        }

        return $entries;
    }
}
