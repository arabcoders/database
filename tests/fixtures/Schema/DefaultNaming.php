<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table]
final class DefaultNaming
{
    #[Column(type: ColumnType::Int, length: 11, primary: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $camelCaseField = '';
}
