<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;

final class Identifier
{
    /**
     * Quote an SQL identifier, preserving wildcard segments.
     * @param DialectInterface $dialect Dialect.
     * @param string $identifier Identifier.
     * @return string
     */
    public static function quote(DialectInterface $dialect, string $identifier): string
    {
        if ('*' === $identifier) {
            return $identifier;
        }

        if (str_contains($identifier, '.')) {
            $parts = array_map('trim', explode('.', $identifier));
            $quoted = [];
            foreach ($parts as $part) {
                $quoted[] = '*' === $part ? '*' : $dialect->quoteIdentifier($part);
            }
            return implode('.', $quoted);
        }

        return $dialect->quoteIdentifier($identifier);
    }

    /**
     * Quote an identifier and append a quoted alias when provided.
     * @param DialectInterface $dialect Dialect.
     * @param string $identifier Identifier.
     * @param ?string $alias Alias.
     * @return string
     */

    public static function quoteWithAlias(DialectInterface $dialect, string $identifier, ?string $alias): string
    {
        $sql = self::quote($dialect, $identifier);
        if (null === $alias || '' === $alias) {
            return $sql;
        }

        return $sql . ' AS ' . $dialect->quoteIdentifier($alias);
    }
}
