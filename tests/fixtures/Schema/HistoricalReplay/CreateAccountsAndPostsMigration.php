<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\HistoricalReplay;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '1', name: 'create_accounts_and_posts')]
final class CreateAccountsAndPostsMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('accounts', function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
            $table->column('name', ColumnType::Text);
        });
        $blueprint->createTable('posts', function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
            $table->column('body', ColumnType::Text);
        });
    }
}
