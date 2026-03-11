<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\ForeignKey;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(primaryKey: ['partA', 'partB'])]
#[Index(columns: ['partA', 'partB'])]
#[Unique(columns: ['partA', 'partB'])]
#[ForeignKey(referencesTable: 'other_table', referencesColumns: ['id'], columns: ['partA'])]
final class CompositeMapping
{
    #[Column(type: ColumnType::VarChar, length: 10)]
    public string $partA = '';

    #[Column(type: ColumnType::VarChar, length: 10)]
    public string $partB = '';
}
