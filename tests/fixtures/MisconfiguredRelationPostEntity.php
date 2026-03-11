<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'misconfigured_posts')]
final class MisconfiguredRelationPostEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::Int, name: 'user_id')]
    public int $userId = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $title = '';
}
