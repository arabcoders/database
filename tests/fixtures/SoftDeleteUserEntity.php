<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Orm\SoftDelete;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'soft_delete_users')]
#[SoftDelete(column: 'deleted_at')]
final class SoftDeleteUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::DateTime, nullable: true, name: 'deleted_at')]
    public ?string $deletedAt = null;
}
