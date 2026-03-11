<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'user_profile')]
#[Index(columns: ['email'])]
final class UserProfile
{
    #[Column(type: ColumnType::Int, length: 11, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Unique]
    public string $email = '';

    #[Column(type: ColumnType::VarChar, length: 255, name: 'display_name')]
    public string $displayName = '';
}
