<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\HistoricalReplay;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;

#[Migration(id: '2', name: 'legacy_account_checkpoint')]
final class LegacyAccountCheckpointMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $from = new SchemaDefinition();
        $fromTable = new TableDefinition('accounts');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $fromTable->setPrimaryKey(['id']);
        $from->addTable($fromTable);

        $to = new SchemaDefinition();
        $toTable = new TableDefinition('accounts');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $toTable->addColumn(new ColumnDefinition('email', ColumnType::Text, nullable: true));
        $toTable->setPrimaryKey(['id']);
        $to->addTable($toTable);

        $blueprint->useMigrationPlan(new SchemaMigrationPlan($from, $to, []));
    }
}
