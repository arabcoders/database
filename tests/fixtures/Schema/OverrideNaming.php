<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'override_naming', primaryKey: ['customId'])]
final class OverrideNaming
{
    #[Column(type: ColumnType::Int, length: 11, name: 'custom_id')]
    public int $customId = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $PascalField = '';
}
