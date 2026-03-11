<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'empty_table')]
final class NoColumnEntity
{
    public string $name = '';

    #[Column(type: ColumnType::Int, primary: true)]
    private int $ignored = 0;
}
