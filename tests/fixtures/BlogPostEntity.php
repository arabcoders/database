<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Orm\BelongsTo;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'posts')]
final class BlogPostEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::Int, name: 'user_id')]
    public int $userId = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $title = '';

    #[BelongsTo(target: BlogUserEntity::class, foreignKey: 'userId', ownerKey: 'id')]
    public ?BlogUserEntity $user = null;
}
