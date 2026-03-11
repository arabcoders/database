<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Transformer\ArrayTransformer;
use arabcoders\database\Transformer\Transform;

#[Table(name: 'auto_nullable_transforms')]
final class AutoNullableTransformEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::LongText, nullable: true)]
    #[Transform(ArrayTransformer::class)]
    public ?array $tags = null;
}
