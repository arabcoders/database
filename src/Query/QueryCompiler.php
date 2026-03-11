<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;
use RuntimeException;

final class QueryCompiler
{
    /**
     * Execute compile for this query compiler.
     * @param QueryInterface $query Query.
     * @param DialectInterface $dialect Dialect.
     * @param ParameterBag $params Params.
     * @param bool $allowWith Allow with.
     * @return string
     * @throws RuntimeException
     */
    public static function compile(
        QueryInterface $query,
        DialectInterface $dialect,
        ParameterBag $params,
        bool $allowWith = true,
    ): string {
        $compiled = $query->toSql($dialect);
        $sql = $compiled['sql'];

        if (!$allowWith && 1 === preg_match('/^\s*WITH\s+/i', $sql)) {
            throw new RuntimeException('Subquery cannot include WITH clause.');
        }

        return self::mergeParams($sql, $compiled['params'], $params);
    }

    public static function compileSubquery(QueryInterface $query, DialectInterface $dialect, ParameterBag $params): string
    {
        return self::compile($query, $dialect, $params, false);
    }

    /**
     * @param array<string,mixed> $compiledParams
     */
    private static function mergeParams(string $sql, array $compiledParams, ParameterBag $params): string
    {
        $mapping = [];
        foreach ($compiledParams as $key => $value) {
            $mapping[$key] = $params->add($value);
        }

        if (empty($mapping)) {
            return $sql;
        }

        $keys = array_keys($mapping);
        usort($keys, static fn(string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            $sql = str_replace($key, $mapping[$key], $sql);
        }

        return $sql;
    }
}
