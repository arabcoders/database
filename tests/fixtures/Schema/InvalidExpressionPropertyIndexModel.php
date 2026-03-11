<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'invalid_expression_property_index_model')]
final class InvalidExpressionPropertyIndexModel
{
    #[Column(type: ColumnType::Int, primary: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Index(expression: '(lower(email))')]
    public string $email = '';
}
