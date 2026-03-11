<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Orm\BelongsTo;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'profiles')]
final class BlogProfileEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::Int, name: 'user_id')]
    public int $userId = 0;

    #[Column(type: ColumnType::VarChar, length: 255, name: 'display_name')]
    public string $displayName = '';

    #[BelongsTo(target: BlogUserEntity::class, foreignKey: 'user_id', ownerKey: 'id')]
    public ?BlogUserEntity $user = null;
}
