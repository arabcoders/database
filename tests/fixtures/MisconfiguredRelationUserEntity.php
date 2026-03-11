<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Orm\HasMany;
use arabcoders\database\Attributes\Orm\HasOne;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'users')]
final class MisconfiguredRelationUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[HasMany(target: MisconfiguredRelationPostEntity::class, foreignKey: 'missing_user_id', localKey: 'id')]
    public array $brokenPosts = [];

    #[HasOne(target: MisconfiguredRelationPostEntity::class, foreignKey: 'user_id', localKey: 'missing_local_key')]
    public ?MisconfiguredRelationPostEntity $brokenProfile = null;
}
