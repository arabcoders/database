<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;
use RuntimeException;

final class Condition
{
    private function __construct(
        private string $type,
        private array $payload,
    ) {}

    public static function equals(string $column, mixed $value): self
    {
        return new self('eq', ['column' => $column, 'value' => $value]);
    }

    public static function notEquals(string $column, mixed $value): self
    {
        return new self('neq', ['column' => $column, 'value' => $value]);
    }

    public static function greaterThan(string $column, mixed $value): self
    {
        return new self('gt', ['column' => $column, 'value' => $value]);
    }

    public static function greaterOrEqual(string $column, mixed $value): self
    {
        return new self('gte', ['column' => $column, 'value' => $value]);
    }

    public static function lessThan(string $column, mixed $value): self
    {
        return new self('lt', ['column' => $column, 'value' => $value]);
    }

    public static function lessOrEqual(string $column, mixed $value): self
    {
        return new self('lte', ['column' => $column, 'value' => $value]);
    }

    public static function like(string $column, string $value): self
    {
        return new self('like', ['column' => $column, 'value' => $value]);
    }

    public static function notLike(string $column, string $value): self
    {
        return new self('not_like', ['column' => $column, 'value' => $value]);
    }

    public static function iLike(string $column, string $value): self
    {
        return new self('ilike', ['column' => $column, 'value' => $value]);
    }

    public static function notILike(string $column, string $value): self
    {
        return new self('not_ilike', ['column' => $column, 'value' => $value]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function in(string $column, array $values): self
    {
        return new self('in', ['column' => $column, 'values' => $values]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function notIn(string $column, array $values): self
    {
        return new self('not_in', ['column' => $column, 'values' => $values]);
    }

    public static function isNull(string $column): self
    {
        return new self('is_null', ['column' => $column]);
    }

    public static function isNotNull(string $column): self
    {
        return new self('is_not_null', ['column' => $column]);
    }

    public static function startsWith(string $column, string $value): self
    {
        return new self('starts_with', ['column' => $column, 'value' => $value]);
    }

    public static function endsWith(string $column, string $value): self
    {
        return new self('ends_with', ['column' => $column, 'value' => $value]);
    }

    public static function regex(string $column, string $pattern): self
    {
        return new self('regex', ['column' => $column, 'pattern' => $pattern]);
    }

    public static function notRegex(string $column, string $pattern): self
    {
        return new self('not_regex', ['column' => $column, 'pattern' => $pattern]);
    }

    public static function isDistinctFrom(string $column, mixed $value): self
    {
        return new self('is_distinct_from', ['column' => $column, 'value' => $value]);
    }

    public static function isNotDistinctFrom(string $column, mixed $value): self
    {
        return new self('is_not_distinct_from', ['column' => $column, 'value' => $value]);
    }

    public static function between(string $column, mixed $from, mixed $to): self
    {
        return new self('between', ['column' => $column, 'from' => $from, 'to' => $to]);
    }

    public static function exists(QueryInterface $query): self
    {
        return new self('exists', ['query' => $query]);
    }

    public static function notExists(QueryInterface $query): self
    {
        return new self('not_exists', ['query' => $query]);
    }

    public static function inSubquery(string $column, QueryInterface $query): self
    {
        return new self('in_subquery', ['column' => $column, 'query' => $query]);
    }

    public static function notInSubquery(string $column, QueryInterface $query): self
    {
        return new self('not_in_subquery', ['column' => $column, 'query' => $query]);
    }

    public static function jsonPathEquals(string $column, string $path, mixed $value): self
    {
        return new self('json_path_eq', ['column' => $column, 'path' => $path, 'value' => $value]);
    }

    public static function jsonPathNotEquals(string $column, string $path, mixed $value): self
    {
        return new self('json_path_neq', ['column' => $column, 'path' => $path, 'value' => $value]);
    }

    public static function jsonPathContains(string $column, string $path, mixed $value): self
    {
        return new self('json_path_contains', ['column' => $column, 'path' => $path, 'value' => $value]);
    }

    public static function jsonPathNotContains(string $column, string $path, mixed $value): self
    {
        return new self('json_path_not_contains', ['column' => $column, 'path' => $path, 'value' => $value]);
    }

    public static function jsonPathExists(string $column, string $path): self
    {
        return new self('json_path_exists', ['column' => $column, 'path' => $path]);
    }

    public static function jsonPathNotExists(string $column, string $path): self
    {
        return new self('json_path_not_exists', ['column' => $column, 'path' => $path]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function jsonPathIn(string $column, string $path, array $values): self
    {
        return new self('json_path_in', ['column' => $column, 'path' => $path, 'values' => $values]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function jsonPathNotIn(string $column, string $path, array $values): self
    {
        return new self('json_path_not_in', ['column' => $column, 'path' => $path, 'values' => $values]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function jsonArrayContains(
        string $column,
        array $values,
        string $mode = 'any',
        string $path = '$',
    ): self {
        return new self('json_array_contains', [
            'column' => $column,
            'values' => $values,
            'mode' => $mode,
            'path' => $path,
        ]);
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function jsonArrayNotContains(
        string $column,
        array $values,
        string $mode = 'any',
        string $path = '$',
    ): self {
        return new self('json_array_not_contains', [
            'column' => $column,
            'values' => $values,
            'mode' => $mode,
            'path' => $path,
        ]);
    }

    /**
     * Build a cosine distance vector comparison condition.
     *
     * @param string $column Vector column.
     * @param array<int,float|int> $vector Comparison vector.
     * @param ?string $operator Comparison operator for threshold checks.
     * @param ?float $threshold Optional threshold value.
     * @return self
     */
    public static function vectorCosineDistance(string $column, array $vector, ?string $operator = null, ?float $threshold = null): self
    {
        return new self('vector_cosine', ['column' => $column, 'vector' => $vector, 'operator' => $operator, 'threshold' => $threshold]);
    }

    /**
     * Build an L2 distance vector comparison condition.
     *
     * @param string $column Vector column.
     * @param array<int,float|int> $vector Comparison vector.
     * @param ?string $operator Comparison operator for threshold checks.
     * @param ?float $threshold Optional threshold value.
     * @return self
     */
    public static function vectorL2Distance(string $column, array $vector, ?string $operator = null, ?float $threshold = null): self
    {
        return new self('vector_l2', ['column' => $column, 'vector' => $vector, 'operator' => $operator, 'threshold' => $threshold]);
    }

    /**
     * Build an inner-product distance vector comparison condition.
     *
     * @param string $column Vector column.
     * @param array<int,float|int> $vector Comparison vector.
     * @param ?string $operator Comparison operator for threshold checks.
     * @param ?float $threshold Optional threshold value.
     * @return self
     */
    public static function vectorInnerProductDistance(
        string $column,
        array $vector,
        ?string $operator = null,
        ?float $threshold = null,
    ): self {
        return new self('vector_ip', ['column' => $column, 'vector' => $vector, 'operator' => $operator, 'threshold' => $threshold]);
    }

    public static function columnCompare(string $left, string $operator, string $right): self
    {
        return new self('column_cmp', ['left' => $left, 'operator' => $operator, 'right' => $right]);
    }

    public static function columnEquals(string $left, string $right): self
    {
        return self::columnCompare($left, '=', $right);
    }

    public static function columnNotEquals(string $left, string $right): self
    {
        return self::columnCompare($left, '!=', $right);
    }

    public static function columnGreaterThan(string $left, string $right): self
    {
        return self::columnCompare($left, '>', $right);
    }

    public static function columnGreaterOrEqual(string $left, string $right): self
    {
        return self::columnCompare($left, '>=', $right);
    }

    public static function columnLessThan(string $left, string $right): self
    {
        return self::columnCompare($left, '<', $right);
    }

    public static function columnLessOrEqual(string $left, string $right): self
    {
        return self::columnCompare($left, '<=', $right);
    }

    /**
     * @param array<int,string> $columns
     */
    public static function fullText(array $columns, string $query, ?string $mode = null): self
    {
        return new self('fulltext', ['columns' => $columns, 'query' => $query, 'mode' => $mode]);
    }

    public static function and(self ...$conditions): self
    {
        return new self('and', ['conditions' => $conditions]);
    }

    public static function or(self ...$conditions): self
    {
        return new self('or', ['conditions' => $conditions]);
    }

    public static function raw(string $sql): self
    {
        return new self('raw', ['sql' => $sql]);
    }

    public static function not(self $condition): self
    {
        return new self('not', ['condition' => $condition]);
    }

    /**
     * Compile the condition to SQL and push any values into the parameter bag.
     *
     * @param DialectInterface $dialect SQL dialect used to render expressions.
     * @param ParameterBag $params Parameter bag receiving bound values.
     * @return string
     * @throws RuntimeException If the condition type or requested operator is not supported.
     */
    public function toSql(DialectInterface $dialect, ParameterBag $params): string
    {
        return match ($this->type) {
            'eq' => $this->binarySql($dialect, $params, '=', true),
            'neq' => $this->binarySql($dialect, $params, '!=', true),
            'gt' => $this->binarySql($dialect, $params, '>'),
            'gte' => $this->binarySql($dialect, $params, '>='),
            'lt' => $this->binarySql($dialect, $params, '<'),
            'lte' => $this->binarySql($dialect, $params, '<='),
            'like' => $this->binarySql($dialect, $params, 'LIKE'),
            'not_like' => $this->binarySql($dialect, $params, 'NOT LIKE'),
            'ilike' => $this->iLikeSql($dialect, $params, false),
            'not_ilike' => $this->iLikeSql($dialect, $params, true),
            'in' => $this->inSql($dialect, $params, 'IN'),
            'not_in' => $this->inSql($dialect, $params, 'NOT IN'),
            'is_null' => Identifier::quote($dialect, $this->payload['column']) . ' IS NULL',
            'is_not_null' => Identifier::quote($dialect, $this->payload['column']) . ' IS NOT NULL',
            'starts_with' => $this->startsEndsWithSql($dialect, $params, true),
            'ends_with' => $this->startsEndsWithSql($dialect, $params, false),
            'regex' => $this->regexSql($dialect, $params, false),
            'not_regex' => $this->regexSql($dialect, $params, true),
            'is_distinct_from' => $this->distinctFromSql($dialect, $params, false),
            'is_not_distinct_from' => $this->distinctFromSql($dialect, $params, true),
            'between' => $this->betweenSql($dialect, $params),
            'column_cmp' => $this->columnCompareSql($dialect),
            'exists' => $this->existsSql($dialect, $params, false),
            'not_exists' => $this->existsSql($dialect, $params, true),
            'in_subquery' => $this->subqueryCompareSql($dialect, $params, 'IN'),
            'not_in_subquery' => $this->subqueryCompareSql($dialect, $params, 'NOT IN'),
            'json_path_eq' => $this->jsonPathCompareSql($dialect, $params, 'eq'),
            'json_path_neq' => $this->jsonPathCompareSql($dialect, $params, 'neq'),
            'json_path_contains' => $this->jsonPathContainsSql($dialect, $params, false),
            'json_path_not_contains' => $this->jsonPathContainsSql($dialect, $params, true),
            'json_path_exists' => $this->jsonPathExistsSql($dialect, $params, false),
            'json_path_not_exists' => $this->jsonPathExistsSql($dialect, $params, true),
            'json_path_in' => $this->jsonPathInSql($dialect, $params, false),
            'json_path_not_in' => $this->jsonPathInSql($dialect, $params, true),
            'json_array_contains' => $this->jsonArrayContainsSql($dialect, $params, false),
            'json_array_not_contains' => $this->jsonArrayContainsSql($dialect, $params, true),
            'vector_cosine' => $this->vectorDistanceSql($dialect, $params, 'cosine'),
            'vector_l2' => $this->vectorDistanceSql($dialect, $params, 'l2'),
            'vector_ip' => $this->vectorDistanceSql($dialect, $params, 'ip'),
            'and' => $this->compoundSql($dialect, $params, 'AND'),
            'or' => $this->compoundSql($dialect, $params, 'OR'),
            'not' => $this->notSql($dialect, $params),
            'raw' => (string) $this->payload['sql'],
            'fulltext' => $this->fullTextSql($dialect, $params),
            default => throw new RuntimeException('Unsupported condition type.'),
        };
    }

    private function iLikeSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $value = $this->payload['value'];
        $placeholder = $params->add($value);

        return match ($dialect->name()) {
            'pgsql' => $column . ($negate ? ' NOT ILIKE ' : ' ILIKE ') . $placeholder,
            default => 'LOWER(' . $column . ')' . ($negate ? ' NOT LIKE ' : ' LIKE ') . 'LOWER(' . $placeholder . ')',
        };
    }

    private function startsEndsWithSql(DialectInterface $dialect, ParameterBag $params, bool $startsWith): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $value = (string) ($this->payload['value'] ?? '');
        $needle = $startsWith ? $value . '%' : '%' . $value;
        $placeholder = $params->add($needle);

        return $column . ' LIKE ' . $placeholder;
    }

    private function regexSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $pattern = (string) ($this->payload['pattern'] ?? '');
        $placeholder = $params->add($pattern);

        return match ($dialect->name()) {
            'mysql' => $column . ($negate ? ' NOT REGEXP ' : ' REGEXP ') . $placeholder,
            'pgsql' => $column . ($negate ? ' !~ ' : ' ~ ') . $placeholder,
            'sqlite' => 'REGEXP(' . $placeholder . ', ' . $column . ')' . ($negate ? ' = 0' : ' = 1'),
            default => throw new RuntimeException('Regex conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function distinctFromSql(DialectInterface $dialect, ParameterBag $params, bool $notDistinct): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $value = $this->payload['value'];
        $placeholder = $params->add($value);

        if ('pgsql' === $dialect->name()) {
            return $column . ($notDistinct ? ' IS NOT DISTINCT FROM ' : ' IS DISTINCT FROM ') . $placeholder;
        }

        if ($notDistinct) {
            return '((' . $column . ' = ' . $placeholder . ') OR (' . $column . ' IS NULL AND ' . $placeholder . ' IS NULL))';
        }

        return (
            '(('
            . $column
            . ' != '
            . $placeholder
            . ') OR ('
            . $column
            . ' IS NULL AND '
            . $placeholder
            . ' IS NOT NULL) OR ('
            . $column
            . ' IS NOT NULL AND '
            . $placeholder
            . ' IS NULL))'
        );
    }

    private function notSql(DialectInterface $dialect, ParameterBag $params): string
    {
        $condition = $this->payload['condition'] ?? null;
        if (!$condition instanceof self) {
            throw new RuntimeException('NOT condition requires a nested Condition instance.');
        }

        return 'NOT (' . $condition->toSql($dialect, $params) . ')';
    }

    private function binarySql(DialectInterface $dialect, ParameterBag $params, string $operator, bool $nullAware = false): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $value = $this->payload['value'];

        if ($nullAware && null === $value) {
            return $column . ('=' === $operator ? ' IS NULL' : ' IS NOT NULL');
        }

        $placeholder = $params->add($value);

        return $column . ' ' . $operator . ' ' . $placeholder;
    }

    private function inSql(DialectInterface $dialect, ParameterBag $params, string $operator): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $values = $this->payload['values'] ?? [];

        if (empty($values)) {
            return 'NOT IN' === $operator ? '1 = 1' : '1 = 0';
        }

        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $params->add($value);
        }

        return $column . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
    }

    private function betweenSql(DialectInterface $dialect, ParameterBag $params): string
    {
        $column = Identifier::quote($dialect, $this->payload['column']);
        $from = $params->add($this->payload['from']);
        $to = $params->add($this->payload['to']);

        return $column . ' BETWEEN ' . $from . ' AND ' . $to;
    }

    private function columnCompareSql(DialectInterface $dialect): string
    {
        $operator = strtoupper(trim((string) $this->payload['operator']));
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<='];
        if (!in_array($operator, $allowed, true)) {
            throw new RuntimeException('Unsupported column comparison operator.');
        }

        $left = Identifier::quote($dialect, $this->payload['left']);
        $right = Identifier::quote($dialect, $this->payload['right']);

        return $left . ' ' . $operator . ' ' . $right;
    }

    private function existsSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $query = $this->payload['query'] ?? null;
        if (!$query instanceof QueryInterface) {
            throw new RuntimeException('Exists requires a query.');
        }

        $sql = QueryCompiler::compileSubquery($query, $dialect, $params);

        return ($negate ? 'NOT EXISTS (' : 'EXISTS (') . $sql . ')';
    }

    private function subqueryCompareSql(DialectInterface $dialect, ParameterBag $params, string $operator): string
    {
        $query = $this->payload['query'] ?? null;
        if (!$query instanceof QueryInterface) {
            throw new RuntimeException('Subquery comparison requires a query.');
        }

        $column = Identifier::quote($dialect, $this->payload['column']);
        $sql = QueryCompiler::compileSubquery($query, $dialect, $params);

        return $column . ' ' . $operator . ' (' . $sql . ')';
    }

    private function compoundSql(DialectInterface $dialect, ParameterBag $params, string $glue): string
    {
        $conditions = $this->payload['conditions'] ?? [];
        if (empty($conditions)) {
            return '1 = 1';
        }

        $parts = [];
        foreach ($conditions as $condition) {
            if (!$condition instanceof self) {
                continue;
            }
            $parts[] = '(' . $condition->toSql($dialect, $params) . ')';
        }

        return implode(' ' . $glue . ' ', $parts);
    }

    private function fullTextSql(DialectInterface $dialect, ParameterBag $params): string
    {
        $columns = $this->payload['columns'] ?? [];
        $query = $this->payload['query'] ?? null;
        $mode = $this->payload['mode'] ?? null;

        if (!is_array($columns) || empty($columns)) {
            throw new RuntimeException('Fulltext search requires columns.');
        }

        if (!is_string($query) || '' === trim($query)) {
            throw new RuntimeException('Fulltext search requires a query string.');
        }

        if (!$dialect->supportsFullText()) {
            throw new RuntimeException('Fulltext search is not supported for ' . $dialect->name() . '.');
        }

        $normalized = array_values(array_filter(array_map('trim', $columns), static fn(string $column) => '' !== $column));
        if (empty($normalized)) {
            throw new RuntimeException('Fulltext search requires valid columns.');
        }

        $quoted = array_map(static fn(string $column) => Identifier::quote($dialect, $column), $normalized);
        $placeholder = $params->add($query);

        $sql = 'MATCH(' . implode(', ', $quoted) . ') AGAINST (' . $placeholder;
        $mode = is_string($mode) ? strtolower(trim($mode)) : null;
        if (null !== $mode && '' !== $mode) {
            $sql .= ' IN ' . strtoupper($mode) . ' MODE';
        }
        $sql .= ')';

        return $sql;
    }

    private function jsonPathCompareSql(DialectInterface $dialect, ParameterBag $params, string $mode): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $path = (string) ($this->payload['path'] ?? '');
        $value = $this->payload['value'] ?? null;
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new RuntimeException('Unable to encode JSON value.');
        }

        [$jsonPath, $segments] = $this->normalizeJsonPath($path);

        return match ($dialect->name()) {
            'mysql' => $this->mysqlJsonPathCompare($params, $column, $jsonPath, $json, $mode),
            'pgsql' => $this->postgresJsonPathCompare($params, $column, $segments, $json, $mode),
            'sqlite' => $this->sqliteJsonPathCompare($params, $column, $jsonPath, $json, $mode),
            default => throw new RuntimeException('JSON path conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function jsonPathContainsSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $path = (string) ($this->payload['path'] ?? '');
        $value = $this->payload['value'] ?? null;
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new RuntimeException('Unable to encode JSON value.');
        }

        [$jsonPath, $segments] = $this->normalizeJsonPath($path);

        return match ($dialect->name()) {
            'mysql' => $this->mysqlJsonPathContains($params, $column, $jsonPath, $json, $negate),
            'pgsql' => $this->postgresJsonPathContains($params, $column, $segments, $json, $negate),
            'sqlite' => $this->sqliteJsonPathContains($params, $column, $jsonPath, $json, $negate),
            default => throw new RuntimeException('JSON path conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function jsonPathExistsSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $path = (string) ($this->payload['path'] ?? '');

        [$jsonPath, $segments] = $this->normalizeJsonPath($path);

        return match ($dialect->name()) {
            'mysql' => $this->mysqlJsonPathExists($params, $column, $jsonPath, $negate),
            'pgsql' => $this->postgresJsonPathExists($params, $column, $segments, $negate),
            'sqlite' => $this->sqliteJsonPathExists($params, $column, $jsonPath, $negate),
            default => throw new RuntimeException('JSON path conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function jsonPathInSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $path = (string) ($this->payload['path'] ?? '');
        $values = $this->payload['values'] ?? [];

        if (!is_array($values)) {
            throw new RuntimeException('JSON path IN values must be an array.');
        }

        if (empty($values)) {
            return $negate ? '1 = 1' : '1 = 0';
        }

        [$jsonPath, $segments] = $this->normalizeJsonPath($path);

        return match ($dialect->name()) {
            'mysql' => $this->mysqlJsonPathIn($params, $column, $jsonPath, $values, $negate),
            'pgsql' => $this->postgresJsonPathIn($params, $column, $segments, $values, $negate),
            'sqlite' => $this->sqliteJsonPathIn($params, $column, $jsonPath, $values, $negate),
            default => throw new RuntimeException('JSON path conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function jsonArrayContainsSql(DialectInterface $dialect, ParameterBag $params, bool $negate): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $path = (string) ($this->payload['path'] ?? '$');
        $values = $this->payload['values'] ?? [];
        $mode = strtolower(trim((string) ($this->payload['mode'] ?? 'any')));

        if (!is_array($values)) {
            throw new RuntimeException('JSON array contains values must be an array.');
        }

        if (!in_array($mode, ['any', 'all'], true)) {
            throw new RuntimeException('JSON array contains mode must be "any" or "all".');
        }

        if (empty($values)) {
            if ('all' === $mode) {
                return $negate ? '1 = 0' : '1 = 1';
            }

            return $negate ? '1 = 1' : '1 = 0';
        }

        [$jsonPath, $segments] = $this->normalizeJsonPath($path);

        return match ($dialect->name()) {
            'mysql' => $this->mysqlJsonArrayContains($params, $column, $jsonPath, $values, $mode, $negate),
            'pgsql' => $this->postgresJsonArrayContains($params, $column, $segments, $values, $mode, $negate),
            'sqlite' => $this->sqliteJsonArrayContains($params, $column, $jsonPath, $values, $mode, $negate),
            default => throw new RuntimeException('JSON path conditions are not supported for ' . $dialect->name() . '.'),
        };
    }

    private function vectorDistanceSql(DialectInterface $dialect, ParameterBag $params, string $metric): string
    {
        $column = Identifier::quote($dialect, (string) $this->payload['column']);
        $vector = $this->payload['vector'] ?? null;
        $operator = $this->payload['operator'] ?? null;
        $threshold = $this->payload['threshold'] ?? null;

        if (!is_array($vector) || empty($vector)) {
            throw new RuntimeException('Vector values are required.');
        }

        if (null !== $operator && !in_array($operator, ['<', '<=', '>', '>=', '=', '!='], true)) {
            throw new RuntimeException('Unsupported vector operator.');
        }

        $json = json_encode($vector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new RuntimeException('Unable to encode vector value.');
        }

        if ('pgsql' !== $dialect->name()) {
            throw new RuntimeException('Vector conditions are only supported for pgsql.');
        }

        if (null === $operator || null === $threshold) {
            throw new RuntimeException('Vector distance conditions require an operator and threshold.');
        }

        $vectorParam = $params->add($json) . '::vector';
        $expression = match ($metric) {
            'cosine' => $column . ' <=> ' . $vectorParam,
            'l2' => $column . ' <-> ' . $vectorParam,
            'ip' => $column . ' <#> ' . $vectorParam,
            default => throw new RuntimeException('Unsupported vector metric.'),
        };

        $thresholdParam = $params->add($threshold);

        return $expression . ' ' . $operator . ' ' . $thresholdParam;
    }

    private function mysqlJsonPathCompare(ParameterBag $params, string $column, string $path, string $json, string $mode): string
    {
        $pathParam = $params->add($path);
        $valueParam = $params->add($json);
        $extract = 'JSON_EXTRACT(' . $column . ', ' . $pathParam . ')';
        $compare = $extract . ' = CAST(' . $valueParam . ' AS JSON)';

        return 'neq' === $mode ? 'NOT (' . $compare . ')' : $compare;
    }

    /**
     * @param array<int,string> $segments
     */
    private function postgresJsonPathCompare(ParameterBag $params, string $column, array $segments, string $json, string $mode): string
    {
        $extract = $this->postgresJsonExtract($params, $column, $segments);
        $valueParam = $params->add($json);
        $compare = $extract . ' = ' . $valueParam . '::jsonb';

        return 'neq' === $mode ? 'NOT (' . $compare . ')' : $compare;
    }

    private function sqliteJsonPathCompare(ParameterBag $params, string $column, string $path, string $json, string $mode): string
    {
        $pathParam = $params->add($path);
        $valueParam = $params->add($json);
        $extract = 'json_extract(' . $column . ', ' . $pathParam . ')';
        $valueExtract = 'json_extract(' . $valueParam . ', ' . $params->add('$') . ')';
        $compare = $extract . ' = ' . $valueExtract;

        return 'neq' === $mode ? 'NOT (' . $compare . ')' : $compare;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function mysqlJsonPathIn(ParameterBag $params, string $column, string $path, array $values, bool $negate): string
    {
        $pathParam = $params->add($path);
        $extract = 'JSON_EXTRACT(' . $column . ', ' . $pathParam . ')';
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $valueParam = $params->add($json);
            $parts[] = $extract . ' = CAST(' . $valueParam . ' AS JSON)';
        }

        $sql = '(' . implode(' OR ', $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    /**
     * @param array<int,string> $segments
     * @param array<int,mixed> $values
     */
    private function postgresJsonPathIn(ParameterBag $params, string $column, array $segments, array $values, bool $negate): string
    {
        $extract = $this->postgresJsonExtract($params, $column, $segments);
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $valueParam = $params->add($json);
            $parts[] = $extract . ' = ' . $valueParam . '::jsonb';
        }

        $sql = '(' . implode(' OR ', $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function sqliteJsonPathIn(ParameterBag $params, string $column, string $path, array $values, bool $negate): string
    {
        $pathParam = $params->add($path);
        $extract = 'json_extract(' . $column . ', ' . $pathParam . ')';
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $valueParam = $params->add($json);
            $parts[] = $extract . ' = json_extract(' . $valueParam . ', ' . $params->add('$') . ')';
        }

        $sql = '(' . implode(' OR ', $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function mysqlJsonArrayContains(
        ParameterBag $params,
        string $column,
        string $path,
        array $values,
        string $mode,
        bool $negate,
    ): string {
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode([$value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $pathParam = $params->add($path);
            $valueParam = $params->add($json);
            $parts[] = 'JSON_CONTAINS(' . $column . ', ' . $valueParam . ', ' . $pathParam . ') = 1';
        }

        $glue = 'all' === $mode ? ' AND ' : ' OR ';
        $sql = '(' . implode($glue, $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    /**
     * @param array<int,string> $segments
     * @param array<int,mixed> $values
     */
    private function postgresJsonArrayContains(
        ParameterBag $params,
        string $column,
        array $segments,
        array $values,
        string $mode,
        bool $negate,
    ): string {
        $extract = $this->postgresJsonExtract($params, $column, $segments);
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode([$value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $valueParam = $params->add($json);
            $parts[] = $extract . ' @> ' . $valueParam . '::jsonb';
        }

        $glue = 'all' === $mode ? ' AND ' : ' OR ';
        $sql = '(' . implode($glue, $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function sqliteJsonArrayContains(
        ParameterBag $params,
        string $column,
        string $path,
        array $values,
        string $mode,
        bool $negate,
    ): string {
        $parts = [];

        foreach ($values as $value) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $json) {
                throw new RuntimeException('Unable to encode JSON value.');
            }

            $pathParam = $params->add($path);
            $valueParam = $params->add($json);
            $rootParam = $params->add('$');
            $parts[] =
                'EXISTS (SELECT 1 FROM json_each('
                . $column
                . ', '
                . $pathParam
                . ') WHERE json_each.value = json_extract('
                . $valueParam
                . ', '
                . $rootParam
                . '))';
        }

        $glue = 'all' === $mode ? ' AND ' : ' OR ';
        $sql = '(' . implode($glue, $parts) . ')';

        return $negate ? 'NOT ' . $sql : $sql;
    }

    private function mysqlJsonPathContains(ParameterBag $params, string $column, string $path, string $json, bool $negate): string
    {
        $pathParam = $params->add($path);
        $valueParam = $params->add($json);
        $expr = 'JSON_CONTAINS(' . $column . ', ' . $valueParam . ', ' . $pathParam . ')';
        $sql = $expr . ' = 1';

        return $negate ? 'NOT (' . $sql . ')' : $sql;
    }

    /**
     * @param array<int,string> $segments
     */
    private function postgresJsonPathContains(ParameterBag $params, string $column, array $segments, string $json, bool $negate): string
    {
        $extract = $this->postgresJsonExtract($params, $column, $segments);
        $valueParam = $params->add($json);
        $sql = $extract . ' @> ' . $valueParam . '::jsonb';

        return $negate ? 'NOT (' . $sql . ')' : $sql;
    }

    private function sqliteJsonPathContains(ParameterBag $params, string $column, string $path, string $json, bool $negate): string
    {
        $pathParam = $params->add($path);
        $valueParam = $params->add($json);
        $valueExtract = 'json_extract(' . $valueParam . ', ' . $params->add('$') . ')';
        $sql = 'EXISTS (SELECT 1 FROM json_each(' . $column . ', ' . $pathParam . ') WHERE json_each.value = ' . $valueExtract . ')';

        return $negate ? 'NOT (' . $sql . ')' : $sql;
    }

    private function mysqlJsonPathExists(ParameterBag $params, string $column, string $path, bool $negate): string
    {
        $pathParam = $params->add($path);
        $modeParam = $params->add('one');
        $sql = 'JSON_CONTAINS_PATH(' . $column . ', ' . $modeParam . ', ' . $pathParam . ') = 1';

        return $negate ? 'NOT (' . $sql . ')' : $sql;
    }

    /**
     * @param array<int,string> $segments
     */
    private function postgresJsonPathExists(ParameterBag $params, string $column, array $segments, bool $negate): string
    {
        $expr = $this->postgresJsonPathExistsExpression($params, $column, $segments);

        return $negate ? 'NOT (' . $expr . ')' : $expr;
    }

    private function sqliteJsonPathExists(ParameterBag $params, string $column, string $path, bool $negate): string
    {
        $pathParam = $params->add($path);
        $sql = 'json_type(' . $column . ', ' . $pathParam . ') IS NOT NULL';

        return $negate ? 'NOT (' . $sql . ')' : $sql;
    }

    /**
     * @param array<int,string> $segments
     */
    private function postgresJsonExtract(ParameterBag $params, string $column, array $segments): string
    {
        if (empty($segments)) {
            return $column;
        }

        $paramsArray = $params->add('{' . implode(',', $segments) . '}');

        return $column . ' #> ' . $paramsArray . '::text[]';
    }

    /**
     * @param array<int,string> $segments
     */
    private function postgresJsonPathExistsExpression(ParameterBag $params, string $column, array $segments): string
    {
        if (empty($segments)) {
            return $column . ' IS NOT NULL';
        }

        if (1 === count($segments)) {
            $key = $segments[0];
            if ('' === $key) {
                return $column . ' IS NOT NULL';
            }

            if (ctype_digit($key)) {
                $keyParam = $params->add((int) $key);
                return $column . ' -> ' . $keyParam . ' IS NOT NULL';
            }

            $keyParam = $params->add($key);
            return $column . ' ? ' . $keyParam;
        }

        $paramsArray = $params->add('{' . implode(',', $segments) . '}');
        return $column . ' #> ' . $paramsArray . '::text[] IS NOT NULL';
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function normalizeJsonPath(string $path): array
    {
        $trimmed = trim($path);
        if ('' === $trimmed || '$' === $trimmed) {
            return ['$', []];
        }

        if (!str_starts_with($trimmed, '$')) {
            $trimmed = '$.' . ltrim($trimmed, '.');
        }

        $segments = [];
        $clean = ltrim($trimmed, '$');
        if ('' !== $clean) {
            $clean = ltrim($clean, '.');
        }

        if ('' !== $clean) {
            $parts = preg_split('/\./', $clean) ?: [];
            foreach ($parts as $part) {
                if ('' === $part) {
                    continue;
                }

                $offset = 0;
                if (1 === preg_match('/^([a-zA-Z0-9_]+)(.*)$/', $part, $matches)) {
                    if ('' !== $matches[1]) {
                        $segments[] = $matches[1];
                    }
                    $offset = strlen($matches[1]);
                }

                $remainder = substr($part, $offset);
                if ('' === $remainder) {
                    continue;
                }

                if (preg_match_all('/\[(\d+)\]/', $remainder, $indexes)) {
                    foreach ($indexes[1] as $index) {
                        $segments[] = $index;
                    }
                }
            }
        }

        return [$trimmed, $segments];
    }
}
