<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Orm;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SoftDelete
{
    public function __construct(
        public string $column = 'deleted_at',
    ) {}
}
