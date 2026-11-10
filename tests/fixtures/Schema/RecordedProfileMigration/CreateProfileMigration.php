<?php

declare(strict_types=1);

namespace tests\fixtures\Schema\RecordedProfileMigration;

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '240101000000', name: 'create_profile')]
final class CreateProfileMigration extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('user_profile', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('email', ColumnType::VarChar, length: 255);
            $table->index('email');
            $table->unique('email');
        });
    }
}
