<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

final class RawExpression
{
    public function __construct(
        private string $sql,
    ) {}

    public function sql(): string
    {
        return $this->sql;
    }
}
