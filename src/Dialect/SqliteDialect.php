<?php

declare(strict_types=1);

namespace arabcoders\database\Dialect;

final class SqliteDialect implements DialectInterface
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Render a dialect-specific LIMIT/OFFSET clause.
     * @param ?int $limit Limit.
     * @param ?int $offset Offset.
     * @return string
     */

    public function renderLimit(?int $limit, ?int $offset = null): string
    {
        if (null === $limit && null === $offset) {
            return '';
        }

        if (null === $limit && null !== $offset) {
            return 'LIMIT -1 OFFSET ' . $offset;
        }

        if (null === $offset) {
            return 'LIMIT ' . $limit;
        }

        return 'LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsUpsertDoNothing(): bool
    {
        return true;
    }

    public function supportsWindowFunctions(): bool
    {
        return true;
    }

    public function supportsFullText(): bool
    {
        return true;
    }

    public function renderUpsertInsertValue(string $column): string
    {
        return 'excluded.' . $this->quoteIdentifier($column);
    }
}
