<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\Migration;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Connection;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '1', name: 'create_widgets')]
final class TestWidgetsMigration extends SchemaBlueprintMigration
{
    public function __invoke(Connection $runner, Blueprint $blueprint): void
    {
        $blueprint->createTable('widgets', function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
        });
    }
}
