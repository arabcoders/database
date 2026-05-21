<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\BrokenMigration;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Connection;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '1', name: 'create_broken_index_widgets')]
final class TestBrokenIndexMigration extends SchemaBlueprintMigration
{
    public function __invoke(Connection $runner, Blueprint $blueprint): void
    {
        $blueprint->createTable('broken_widgets', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
            $table->column('name', ColumnType::VarChar, length: 255);
            $table->index('name', 'idx_broken_widgets_name');
        });
    }
}
