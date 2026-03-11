<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Unique
{
    public function __construct(
        public ?string $name = null,
        public array $columns = [],
        public array $algorithm = [],
        public ?string $where = null,
        public ?string $expression = null,
    ) {}
}
