<?php

declare(strict_types=1);

namespace arabcoders\database\Dialect;

final class MysqlDialect implements DialectInterface
{
    private const string RETURNING_MIN_VERSION = '8.0.21';

    private bool $supportsReturning;
    private bool $isMariaDb;

    public function __construct(?string $serverVersion = null)
    {
        $this->supportsReturning = self::supportsReturningForVersion($serverVersion);
        $this->isMariaDb = self::isMariaDbVersion($serverVersion);
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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
            return 'LIMIT 18446744073709551615 OFFSET ' . $offset;
        }

        if (null === $offset) {
            return 'LIMIT ' . $limit;
        }

        return 'LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    public function supportsReturning(): bool
    {
        return $this->supportsReturning;
    }

    public function isMariaDb(): bool
    {
        return $this->isMariaDb;
    }

    public function supportsUpsertDoNothing(): bool
    {
        return false;
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
        return 'VALUES(' . $this->quoteIdentifier($column) . ')';
    }

    private static function supportsReturningForVersion(?string $serverVersion): bool
    {
        if (null === $serverVersion) {
            return false;
        }

        $normalized = self::normalizeVersion($serverVersion);
        if (null === $normalized) {
            return false;
        }

        return version_compare($normalized, self::RETURNING_MIN_VERSION, '>=');
    }

    private static function isMariaDbVersion(?string $serverVersion): bool
    {
        if (null === $serverVersion) {
            return false;
        }

        return false !== stripos($serverVersion, 'mariadb');
    }

    private static function normalizeVersion(string $serverVersion): ?string
    {
        $serverVersion = trim($serverVersion);
        if ('' === $serverVersion) {
            return null;
        }

        if (false !== stripos($serverVersion, 'mariadb')) {
            return null;
        }

        if (1 === preg_match('/\d+\.\d+\.\d+/', $serverVersion, $matches)) {
            return $matches[0];
        }

        if (1 === preg_match('/\d+\.\d+/', $serverVersion, $matches)) {
            return $matches[0] . '.0';
        }

        return null;
    }
}
