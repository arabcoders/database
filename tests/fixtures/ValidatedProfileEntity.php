<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Validator\Rules\NotBlank;
use arabcoders\database\Validator\Rules\Range;
use arabcoders\database\Validator\Rules\Regex;
use arabcoders\database\Validator\Validate;

#[Table(name: 'validated_profiles')]
final class ValidatedProfileEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Validate(NotBlank::class)]
    #[Validate(Range::class, min: 3, max: 12)]
    #[Validate(Regex::class, pattern: '/^[a-z0-9_]+$/i', message: 'Username must be alphanumeric with underscores.')]
    public string $username = '';
}
