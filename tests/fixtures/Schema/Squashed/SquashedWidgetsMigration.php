<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\Squashed;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(
    id: '2',
    name: 'squashed_widgets',
    squashedFrom: '1',
    squashedChecksum: '99426129fb29265459349e7fc7d2bd054a864cc186a1c1701b885ad473e0d842',
)]
final class SquashedWidgetsMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('pending_widgets', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->autoIncrement()->primary();
            $table->column('name', ColumnType::Text)->nullable();
        });
    }
}
