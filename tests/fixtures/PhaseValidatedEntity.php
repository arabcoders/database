<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Validator\Rules\NotBlank;
use arabcoders\database\Validator\Rules\Regex;
use arabcoders\database\Validator\Validate;
use arabcoders\database\Validator\ValidationType;

#[Table(name: 'phase_validated_entities')]
final class PhaseValidatedEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Validate(NotBlank::class, ValidationType::CREATE)]
    #[Validate(Regex::class, ValidationType::UPDATE, pattern: '/^[a-z]+$/', message: 'Username must be lowercase letters only on update.')]
    public string $username = '';
}
