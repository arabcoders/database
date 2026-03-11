<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Differ
{
    public function __construct(
        public mixed $callback,
    ) {}
}
