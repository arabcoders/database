<?php

declare(strict_types=1);

namespace tests\fixtures;

use AllowDynamicProperties;
use arabcoders\database\Attributes\Orm\BelongsTo;
use arabcoders\database\Attributes\Orm\BelongsToMany;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'tags')]
#[AllowDynamicProperties]
final class BlogTagEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 120)]
    public string $name = '';

    #[Column(type: ColumnType::Int, name: 'user_id', nullable: true)]
    public ?int $userId = null;

    #[BelongsTo(target: BlogUserEntity::class, foreignKey: 'userId', ownerKey: 'id')]
    public ?BlogUserEntity $user = null;

    #[BelongsToMany(
        target: BlogUserEntity::class,
        pivotTable: 'user_tags',
        foreignPivotKey: 'tag_id',
        relatedPivotKey: 'user_id',
        parentKey: 'id',
        relatedKey: 'id',
        pivotColumns: ['tagged_at'],
    )]
    public array $users = [];
}
