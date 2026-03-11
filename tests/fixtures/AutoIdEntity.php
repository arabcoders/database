<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'auto_ids')]
final class AutoIdEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;
}
