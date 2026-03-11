<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Transformer\ArrayTransformer;
use arabcoders\database\Transformer\DateTransformer;
use arabcoders\database\Transformer\Transform;
use DateTimeInterface;

#[Table(name: 'transformed_notes')]
final class TransformedNoteEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::LongText, nullable: true)]
    #[Transform(ArrayTransformer::class, nullable: true)]
    public ?array $tags = null;

    #[Column(type: ColumnType::DateTime)]
    #[Transform(DateTransformer::class)]
    public DateTimeInterface|string $created = '';
}
