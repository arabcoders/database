<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'multi_keys')]
final class MultiKeyEntity
{
    #[Column(type: ColumnType::Int, primary: true)]
    public int $firstId = 0;

    #[Column(type: ColumnType::Int, primary: true)]
    public int $secondId = 0;
}
