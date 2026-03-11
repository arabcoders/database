<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Validator\Rules\NoSpaces;
use arabcoders\database\Validator\Validate;

#[Table(name: 'validated_users')]
final class ValidatedUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Validate(NoSpaces::class)]
    public string $username = '';
}
