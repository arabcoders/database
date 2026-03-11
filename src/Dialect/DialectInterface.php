<?php

declare(strict_types=1);

namespace arabcoders\database\Dialect;

interface DialectInterface
{
    public function name(): string;

    public function quoteIdentifier(string $identifier): string;

    public function quoteString(string $value): string;

    public function renderLimit(?int $limit, ?int $offset = null): string;

    public function supportsReturning(): bool;

    public function supportsUpsertDoNothing(): bool;

    public function supportsWindowFunctions(): bool;

    public function supportsFullText(): bool;

    public function renderUpsertInsertValue(string $column): string;
}
