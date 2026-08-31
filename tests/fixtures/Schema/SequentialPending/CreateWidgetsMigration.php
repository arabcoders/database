<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\SequentialPending;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '1', name: 'create_pending_widgets')]
final class CreateWidgetsMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('pending_widgets', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
        });
    }
}
