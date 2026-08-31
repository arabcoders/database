<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Blueprint;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use arabcoders\database\Schema\Migration\SchemaStateApplier;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropTableOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use arabcoders\database\Schema\Operation\SchemaOperation;
use arabcoders\database\Schema\SchemaDiff;

final class Blueprint
{
    /**
     * @var array<int,SchemaOperation>
     */
    private array $operations = [];

    private ?SchemaMigrationPlan $migrationPlan = null;

    public function __construct(
        private readonly ?SchemaDefinition $initialState = null,
    ) {}

    /**
     * Execute create table for this blueprint.
     * @param string $name Name.
     * @param callable $callback Callback.
     * @param array $options Options.
     * @return void
     */

    public function createTable(string $name, callable $callback, array $options = []): void
    {
        $table = new TableBlueprint($this, $name, TableBlueprint::MODE_CREATE, $options);
        $callback($table);

        $definition = $table->toTableDefinition();
        $this->operations[] = new CreateTableOperation($definition);

        foreach ($table->getIndexes() as $index) {
            $this->operations[] = new AddIndexOperation($name, $index);
        }
    }

    public function dropTable(string $name): void
    {
        $this->operations[] = new DropTableOperation(new TableDefinition(name: $name));
    }

    public function renameTable(string $from, string $to): void
    {
        $this->operations[] = new RenameTableOperation($from, $to);
    }

    public function table(string $name, callable $callback): void
    {
        $table = new TableBlueprint($this, $name, TableBlueprint::MODE_ALTER);
        $callback($table);
    }

    public function addOperation(SchemaOperation $operation): void
    {
        $this->operations[] = $operation;
    }

    public function useMigrationPlan(SchemaMigrationPlan $plan): void
    {
        $this->migrationPlan = $plan;
    }

    public function getMigrationPlan(): ?SchemaMigrationPlan
    {
        return $this->migrationPlan;
    }

    /**
     * @return array<int,SchemaOperation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function toDiff(): SchemaDiff
    {
        if (null === $this->migrationPlan && null !== $this->initialState) {
            return new SchemaStateApplier()->diff($this->initialState, $this->operations);
        }

        return new SchemaDiff(
            $this->migrationPlan->from ?? new SchemaDefinition(),
            $this->migrationPlan->to ?? new SchemaDefinition(),
            $this->operations,
        );
    }

    public function toHistoricalDiff(SchemaDefinition $state): SchemaDiff
    {
        $applier = new SchemaStateApplier();
        $before = null === $this->migrationPlan ? $state : $applier->overlay($state, $this->migrationPlan->from);
        if ([] === $this->operations && null !== $this->migrationPlan) {
            return new SchemaDiff($before, $applier->overlay($before, $this->migrationPlan->to), []);
        }

        return $applier->diff($before, $this->operations);
    }
}
