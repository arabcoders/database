<?php

declare(strict_types=1);

namespace tests\fixtures;

use AllowDynamicProperties;
use arabcoders\database\Attributes\Orm\BelongsToMany;
use arabcoders\database\Attributes\Orm\HasMany;
use arabcoders\database\Attributes\Orm\HasOne;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'users')]
#[AllowDynamicProperties]
final class BlogUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[HasMany(target: BlogPostEntity::class, foreignKey: 'user_id', localKey: 'id')]
    public array $posts = [];

    #[HasOne(target: BlogProfileEntity::class, foreignKey: 'user_id', localKey: 'id')]
    public ?BlogProfileEntity $profile = null;

    #[BelongsToMany(
        target: BlogTagEntity::class,
        pivotTable: 'user_tags',
        foreignPivotKey: 'user_id',
        relatedPivotKey: 'tag_id',
        parentKey: 'id',
        relatedKey: 'id',
        pivotColumns: ['tagged_at'],
    )]
    public array $tags = [];
}
