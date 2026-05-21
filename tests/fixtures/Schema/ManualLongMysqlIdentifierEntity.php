<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table('manual_long_mysql_identifier_table_name_that_exceeds_sixty_four_characters_total')]
final class ManualLongMysqlIdentifierEntity
{
    #[Column(type: ColumnType::VarChar, length: 64)]
    #[Unique(name: 'manual_long_mysql_identifier_name_that_exceeds_sixty_four_chars_total', columns: ['value'])]
    public string $value;
}
