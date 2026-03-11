<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Table
{
    public ?string $prevName;

    public function __construct(
        public ?string $name = null,
        public array $primaryKey = [],
        public array $engine = [],
        public array $charset = [],
        public array $collation = [],
        ?string $prevName = null,
    ) {
        $this->prevName = $prevName;
    }
}
