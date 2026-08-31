<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\HistoricalReplay;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '3', name: 'add_post_title')]
final class AddPostTitleMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->table('posts', static function (TableBlueprint $table): void {
            $table->addColumnOperation(new ColumnDefinition('title', ColumnType::Text, nullable: true));
        });
    }
}
