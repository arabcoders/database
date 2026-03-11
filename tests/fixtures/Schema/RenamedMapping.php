<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'renamed_mapping', prevName: 'legacy_mapping')]
final class RenamedMapping
{
    #[Column(type: ColumnType::Int, length: 11, prevName: 'legacy_id')]
    public int $id = 0;
}
