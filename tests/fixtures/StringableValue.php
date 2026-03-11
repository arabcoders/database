<?php

declare(strict_types=1);

namespace tests\fixtures;

final readonly class StringableValue implements \Stringable
{
    public function __construct(
        private string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
