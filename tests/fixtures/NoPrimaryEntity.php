<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'no_primary')]
final class NoPrimaryEntity
{
    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $name = '';
}
