<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'nullable_users')]
final class NullableUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public ?int $id = null;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::VarChar, length: 255, name: 'display_name')]
    public string $displayName = '';
}
