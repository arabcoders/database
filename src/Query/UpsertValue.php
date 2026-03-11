<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

final class UpsertValue
{
    public function __construct(
        private string $column,
    ) {}

    public static function inserted(string $column): self
    {
        return new self($column);
    }

    public function column(): string
    {
        return $this->column;
    }
}
