<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;
use RuntimeException;

final class DeleteQuery implements QueryInterface, CacheableQueryInterface
{
    use Macroable;

    private ?Condition $where = null;

    private ?string $alias = null;

    /**
     * @var array<int,array{name:string,query:QueryInterface,recursive:bool}>
     */
    private array $with = [];

    private bool $withRecursive = false;

    /**
     * @var array<int,array{type:string,table:string|QueryInterface,alias:string|null,on:Condition|string|null,subquery:bool}>
     */
    private array $joins = [];

    /**
     * @var array<int,string|RawExpression>
     */
    private array $returning = [];

    /**
     * @var array<int,array{type:string,column:string,direction:string|null}>
     */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;

    private ?string $cacheKey = null;
    private ?int $cacheTtl = null;

    public function __construct(
        private string $table,
    ) {
        $this->table = TableResolver::resolve($table);
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = TableResolver::resolve($table);
        $this->alias = $alias;

        return $this;
    }

    /**
     * Attach a common table expression used by this delete query.
     *
     * @param string $name CTE name.
     * @param QueryInterface $query CTE query.
     * @param bool $recursive Whether to mark the WITH clause as recursive.
     * @return self
     * @throws RuntimeException If the CTE name is empty.
     */
    public function with(string $name, QueryInterface $query, bool $recursive = false): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('CTE name is required.');
        }

        $this->with[] = ['name' => $name, 'query' => $query, 'recursive' => $recursive];
        if ($recursive) {
            $this->withRecursive = true;
        }

        return $this;
    }

    /**
     * Add a join clause to the query.
     * @param string $table Table.
     * @param ?string $alias Alias.
     * @param Condition|string|null $on On.
     * @param string $type Type.
     * @return self
     */

    public function join(string $table, ?string $alias = null, Condition|string|null $on = null, string $type = 'INNER'): self
    {
        $type = $this->normalizeJoinType($type);
        $this->joins[] = [
            'type' => $type,
            'table' => TableResolver::resolve($table),
            'alias' => $alias,
            'on' => $on,
            'subquery' => false,
        ];

        return $this;
    }

    /**
     * Join a subquery in the DELETE statement.
     *
     * @param QueryInterface $query Subquery to join.
     * @param string $alias Alias used to reference the subquery.
     * @param Condition|string|null $on Join condition.
     * @param string $type Join type.
     * @return self
     * @throws RuntimeException If the alias is empty.
     */
    public function joinSubquery(
        QueryInterface $query,
        string $alias,
        Condition|string|null $on = null,
        string $type = 'INNER',
    ): self {
        $alias = trim($alias);
        if ('' === $alias) {
            throw new RuntimeException('Subquery requires an alias.');
        }

        $type = $this->normalizeJoinType($type);
        $this->joins[] = [
            'type' => $type,
            'table' => $query,
            'alias' => $alias,
            'on' => $on,
            'subquery' => true,
        ];

        return $this;
    }

    public function innerJoin(string $table, ?string $alias = null, Condition|string|null $on = null): self
    {
        return $this->join($table, $alias, $on, 'INNER');
    }

    public function leftJoin(string $table, ?string $alias = null, Condition|string|null $on = null): self
    {
        return $this->join($table, $alias, $on, 'LEFT');
    }

    public function rightJoin(string $table, ?string $alias = null, Condition|string|null $on = null): self
    {
        return $this->join($table, $alias, $on, 'RIGHT');
    }

    public function crossJoin(string $table, ?string $alias = null): self
    {
        return $this->join($table, $alias, null, 'CROSS');
    }

    public function where(Condition $condition): self
    {
        $this->where = $condition;

        return $this;
    }

    /**
     * @param array<int,string|RawExpression> $columns
     */
    public function returning(array $columns): self
    {
        $this->returning = $columns;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = $this->normalizeDirection($direction) ?? 'ASC';
        $this->orderBy[] = ['type' => 'column', 'column' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByRaw(string $expression, ?string $direction = null): self
    {
        $direction = $this->normalizeDirection($direction);
        $this->orderBy[] = ['type' => 'raw', 'column' => $expression, 'direction' => $direction];

        return $this;
    }

    public function limit(?int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        $this->offset = $offset;

        return $this;
    }

    public function cache(string $key, ?int $ttl = null): self
    {
        $this->cacheKey = $key;
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * Return a normalized cache key used for query caching.
     * @return ?string
     */

    public function cacheKey(): ?string
    {
        $key = trim((string) $this->cacheKey);
        if ('' === $key) {
            return null;
        }

        return $key;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    /**
     * Compile the delete query into SQL and bound parameters.
     *
     * @param DialectInterface $dialect SQL dialect used to render identifiers and clauses.
     * @return array{sql:string,params:array<string,mixed>}
     * @throws RuntimeException If no WHERE condition is set.
     * @throws RuntimeException If the dialect does not support joined deletes.
     */
    public function toSql(DialectInterface $dialect): array
    {
        if (null === $this->where) {
            throw new RuntimeException('Delete requires a where clause.');
        }

        $params = new ParameterBag();
        $withSql = $this->renderWith($dialect, $params);

        $whereParts = [$this->where->toSql($dialect, $params)];

        if (!empty($this->joins)) {
            if ('mysql' === $dialect->name()) {
                $target = $this->alias ?? $this->table;
                $sql =
                    'DELETE '
                    . Identifier::quote($dialect, $target)
                    . ' FROM '
                    . Identifier::quoteWithAlias($dialect, $this->table, $this->alias)
                    . $this->renderJoins($dialect, $params)
                    . ' WHERE '
                    . $this->renderWhereParts($whereParts);
            } elseif ('pgsql' === $dialect->name()) {
                $from = $this->renderFrom($dialect, $params);
                $whereParts = array_merge($from['conditions'], $whereParts);
                $sql =
                    'DELETE FROM '
                    . Identifier::quoteWithAlias($dialect, $this->table, $this->alias)
                    . $from['sql']
                    . ' WHERE '
                    . $this->renderWhereParts($whereParts);
            } else {
                throw new RuntimeException('Delete joins are not supported for ' . $dialect->name() . '.');
            }
        } else {
            $sql =
                'DELETE FROM '
                . Identifier::quoteWithAlias($dialect, $this->table, $this->alias)
                . ' WHERE '
                . $this->renderWhereParts($whereParts);
        }

        $sql .= $this->renderOrderBy($dialect);
        $sql .= $this->renderLimit($dialect);
        $sql .= $this->renderReturning($dialect);

        if ('' !== $withSql) {
            $sql = $withSql . ' ' . $sql;
        }

        return ['sql' => $sql, 'params' => $params->all()];
    }

    private function renderWith(DialectInterface $dialect, ParameterBag $params): string
    {
        if (empty($this->with)) {
            return '';
        }

        $parts = [];
        foreach ($this->with as $entry) {
            $name = $dialect->quoteIdentifier($entry['name']);
            $subquery = QueryCompiler::compileSubquery($entry['query'], $dialect, $params);
            $parts[] = $name . ' AS (' . $subquery . ')';
        }

        $keyword = $this->withRecursive ? 'WITH RECURSIVE' : 'WITH';

        return $keyword . ' ' . implode(', ', $parts);
    }

    private function renderJoins(DialectInterface $dialect, ParameterBag $params): string
    {
        if (empty($this->joins)) {
            return '';
        }

        if ('mysql' !== $dialect->name()) {
            throw new RuntimeException('Delete joins are not supported for ' . $dialect->name() . '.');
        }

        $sql = '';
        foreach ($this->joins as $join) {
            $target = $join['subquery']
                ? $this->renderSubqueryTarget($dialect, $params, $join['table'], $join['alias'])
                : Identifier::quoteWithAlias($dialect, $join['table'], $join['alias']);

            $sql .= ' ' . $join['type'] . ' JOIN ' . $target;
            if (null !== $join['on']) {
                if (is_string($join['on']) && '' === trim($join['on'])) {
                    continue;
                }
                $sql .= ' ON ' . $this->renderJoinCondition($join['on'], $dialect, $params);
            }
        }

        return $sql;
    }

    /**
     * @return array{sql:string,conditions:array<int,string>}
     */
    private function renderFrom(DialectInterface $dialect, ParameterBag $params): array
    {
        if (empty($this->joins) || 'pgsql' !== $dialect->name()) {
            return ['sql' => '', 'conditions' => []];
        }

        $targets = [];
        $conditions = [];
        foreach ($this->joins as $join) {
            if ('INNER' !== $join['type']) {
                throw new RuntimeException('Postgres delete joins only support INNER joins.');
            }

            $targets[] = $join['subquery']
                ? $this->renderSubqueryTarget($dialect, $params, $join['table'], $join['alias'])
                : Identifier::quoteWithAlias($dialect, $join['table'], $join['alias']);

            if (null !== $join['on']) {
                if (is_string($join['on']) && '' === trim($join['on'])) {
                    continue;
                }
                $conditions[] = $this->renderJoinCondition($join['on'], $dialect, $params);
            }
        }

        if (empty($targets)) {
            return ['sql' => '', 'conditions' => []];
        }

        return [
            'sql' => ' USING ' . implode(', ', $targets),
            'conditions' => $conditions,
        ];
    }

    private function renderSubqueryTarget(
        DialectInterface $dialect,
        ParameterBag $params,
        QueryInterface $query,
        ?string $alias,
    ): string {
        $alias = trim((string) $alias);
        if ('' === $alias) {
            throw new RuntimeException('Subquery requires an alias.');
        }

        $sql = QueryCompiler::compileSubquery($query, $dialect, $params);

        return '(' . $sql . ') AS ' . $dialect->quoteIdentifier($alias);
    }

    private function renderJoinCondition(Condition|string $on, DialectInterface $dialect, ParameterBag $params): string
    {
        if ($on instanceof Condition) {
            return $on->toSql($dialect, $params);
        }

        return $on;
    }

    private function normalizeJoinType(string $type): string
    {
        $type = strtoupper(trim((string) preg_replace('/\s+/', ' ', $type)));
        $allowed = ['INNER', 'LEFT', 'RIGHT', 'CROSS', 'LEFT OUTER', 'RIGHT OUTER'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Unsupported join type: ' . $type);
        }

        return $type;
    }

    private function normalizeDirection(?string $direction): ?string
    {
        if (null === $direction || '' === trim($direction)) {
            return null;
        }

        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * @param array<int,string> $parts
     */
    private function renderWhereParts(array $parts): string
    {
        $normalized = array_filter(array_map(trim(...), $parts), static fn(string $part) => '' !== $part);

        return implode(' AND ', $normalized);
    }

    private function renderOrderBy(DialectInterface $dialect): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        $orderParts = [];
        foreach ($this->orderBy as $order) {
            $part = 'raw' === $order['type']
                ? $order['column']
                : Identifier::quote($dialect, $order['column']);
            if (null !== $order['direction']) {
                $part .= ' ' . $order['direction'];
            }
            $orderParts[] = $part;
        }

        return ' ORDER BY ' . implode(', ', $orderParts);
    }

    private function renderLimit(DialectInterface $dialect): string
    {
        $limitSql = $dialect->renderLimit($this->limit, $this->offset);
        if ('' === $limitSql) {
            return '';
        }

        return ' ' . $limitSql;
    }

    private function renderReturning(DialectInterface $dialect): string
    {
        if (empty($this->returning)) {
            return '';
        }

        if (!$dialect->supportsReturning()) {
            throw new RuntimeException('RETURNING is not supported for ' . $dialect->name() . '.');
        }

        $parts = [];
        foreach ($this->returning as $column) {
            if ($column instanceof RawExpression) {
                $parts[] = $column->sql();
                continue;
            }
            $parts[] = Identifier::quote($dialect, $column);
        }

        if (empty($parts)) {
            throw new RuntimeException('Returning columns are required.');
        }

        return ' RETURNING ' . implode(', ', $parts);
    }
}
