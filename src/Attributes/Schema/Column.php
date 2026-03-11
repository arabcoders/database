<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Schema;

use arabcoders\database\Schema\Definition\ColumnType;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public ?string $prevName;

    public function __construct(
        public ColumnType $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unsigned = false,
        public bool $nullable = false,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public bool $hasDefault = false,
        public mixed $default = null,
        public bool $defaultIsExpression = false,
        public ?string $name = null,
        public ?string $typeName = null,
        ?string $prevName = null,
        public array $charset = [],
        public array $collation = [],
        public ?string $comment = null,
        public ?string $onUpdate = null,
        public ?array $allowed = null,
        public bool $check = false,
        public ?string $checkExpression = null,
        public bool $generated = false,
        public ?string $generatedExpression = null,
        public ?bool $generatedStored = null,
        public array $hooks = [],
    ) {
        $this->prevName = $prevName;
    }
}
