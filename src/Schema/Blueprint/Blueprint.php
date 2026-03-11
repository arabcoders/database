<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Blueprint;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
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

    /**
     * @return array<int,SchemaOperation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function toDiff(): SchemaDiff
    {
        return new SchemaDiff(new SchemaDefinition(), new SchemaDefinition(), $this->operations);
    }
}
