<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table('long_mysql_indexes')]
final class LongMysqlIndexEntity
{
    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Unique(columns: ['firstValue', 'secondValue', 'thirdValue', 'fourthValue'])]
    public string $firstValue;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $secondValue;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $thirdValue;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $fourthValue;
}
