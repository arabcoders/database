<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\Squashed;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '3', name: 'add_widget_status')]
final class AddWidgetStatusMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->table('pending_widgets', static function (TableBlueprint $table): void {
            $table->addColumnOperation(new ColumnDefinition('status', ColumnType::Text, nullable: true));
        });
    }
}
