<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;
use RuntimeException;

// @mago-expect lint:too-many-properties select query state includes distinct, with, joins, group, and cache fields.
final class SelectQuery implements QueryInterface, CacheableQueryInterface
{
    use Macroable;

    /**
     * @var array<int,array{type:string,value:string,alias?:string}>
     */
    private array $columns = [['type' => 'raw', 'value' => '*']];

    private bool $distinct = false;

    private ?string $alias = null;

    private ?QueryInterface $fromSubquery = null;

    /**
     * @var array<int,array{name:string,query:QueryInterface,recursive:bool}>
     */
    private array $with = [];

    private bool $withRecursive = false;

    /**
     * @var array<int,array{type:string,query:SelectQuery}>
     */
    private array $unions = [];

    private ?string $lock = null;

    /**
     * @var array<int,array{type:string,table:string|QueryInterface,alias:string|null,on:Condition|string|null,subquery:bool}>
     */
    private array $joins = [];

    private ?Condition $where = null;

    /**
     * @var array<int,array{type:string,value:string}>
     */
    private array $groupBy = [];

    private ?Condition $having = null;

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
        ?string $alias = null,
    ) {
        $this->table = TableResolver::resolve($table);
        $this->alias = $alias;
    }

    /**
     * Execute from for this select query.
     * @param string $table Table.
     * @param ?string $alias Alias.
     * @return self
     */

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = TableResolver::resolve($table);
        $this->alias = $alias;
        $this->fromSubquery = null;

        return $this;
    }

    /**
     * Use a subquery as the FROM source.
     *
     * @param QueryInterface $query Subquery to select from.
     * @param string $alias Alias used to reference the subquery.
     * @return self
     * @throws RuntimeException If the alias is empty.
     */
    public function fromSubquery(QueryInterface $query, string $alias): self
    {
        $alias = trim($alias);
        if ('' === $alias) {
            throw new RuntimeException('Subquery requires an alias.');
        }

        $this->fromSubquery = $query;
        $this->alias = $alias;

        return $this;
    }

    /**
     * Attach a common table expression used by this select query.
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

    public function union(SelectQuery $query): self
    {
        $this->unions[] = ['type' => 'UNION', 'query' => $query];

        return $this;
    }

    public function unionAll(SelectQuery $query): self
    {
        $this->unions[] = ['type' => 'UNION ALL', 'query' => $query];

        return $this;
    }

    public function intersect(SelectQuery $query): self
    {
        $this->unions[] = ['type' => 'INTERSECT', 'query' => $query];

        return $this;
    }

    public function except(SelectQuery $query): self
    {
        $this->unions[] = ['type' => 'EXCEPT', 'query' => $query];

        return $this;
    }

    public function forUpdate(): self
    {
        $this->lock = 'FOR UPDATE';

        return $this;
    }

    public function lockInShareMode(): self
    {
        $this->lock = 'LOCK IN SHARE MODE';

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * @param array<int,string> $columns
     */
    public function select(array $columns): self
    {
        $this->columns = [];
        if (empty($columns)) {
            return $this;
        }

        foreach ($columns as $column) {
            $this->columns[] = ['type' => 'column', 'value' => $column];
        }

        return $this;
    }

    public function selectRaw(string $expression): self
    {
        $this->clearDefaultColumns();
        $this->columns[] = ['type' => 'raw', 'value' => $expression];

        return $this;
    }

    public function selectAs(string $column, string $alias): self
    {
        $this->clearDefaultColumns();
        $this->columns[] = ['type' => 'alias', 'value' => $column, 'alias' => $alias];

        return $this;
    }

    public function selectCount(string $column = '*', ?string $alias = null): self
    {
        return $this->selectAggregate('count', $column, $alias);
    }

    public function selectSum(string $column, ?string $alias = null): self
    {
        return $this->selectAggregate('sum', $column, $alias);
    }

    public function selectAvg(string $column, ?string $alias = null): self
    {
        return $this->selectAggregate('avg', $column, $alias);
    }

    public function selectMin(string $column, ?string $alias = null): self
    {
        return $this->selectAggregate('min', $column, $alias);
    }

    public function selectMax(string $column, ?string $alias = null): self
    {
        return $this->selectAggregate('max', $column, $alias);
    }

    public function where(Condition $condition): self
    {
        $this->where = $condition;

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
     * Join a subquery in the SELECT statement.
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

    /**
     * @param array<int,string> $columns
     */
    public function groupBy(array $columns): self
    {
        $this->groupBy = [];
        foreach ($columns as $column) {
            $this->groupBy[] = ['type' => 'column', 'value' => $column];
        }

        return $this;
    }

    public function groupByRaw(string $expression): self
    {
        $this->groupBy[] = ['type' => 'raw', 'value' => $expression];

        return $this;
    }

    public function having(Condition $condition): self
    {
        $this->having = $condition;

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
     * Compile the select query into SQL and bound parameters.
     *
     * @param DialectInterface $dialect SQL dialect used to render identifiers and clauses.
     * @return array{sql:string,params:array<string,mixed>}
     * @throws RuntimeException If a subquery source is missing an alias.
     * @throws RuntimeException If a requested SQL feature is not supported by the dialect.
     */
    public function toSql(DialectInterface $dialect): array
    {
        $params = new ParameterBag();
        $withSql = $this->renderWith($dialect, $params);
        $this->assertSetOperationsSupported($dialect);

        $includeOrderLimit = empty($this->unions);
        $baseSql = $this->buildSelectSql($dialect, $params, $includeOrderLimit);

        if (empty($this->unions)) {
            $sql = $baseSql;
        } else {
            $parts = [$baseSql];
            foreach ($this->unions as $union) {
                $unionSql = QueryCompiler::compileSubquery($union['query'], $dialect, $params);
                $parts[] = $union['type'] . ' ' . $unionSql;
            }
            $sql = implode(' ', $parts);
            $sql .= $this->renderOrderBy($dialect);
            $sql .= $this->renderLimit($dialect);
        }

        $sql .= $this->renderLock($dialect);

        if ('' !== $withSql) {
            $sql = $withSql . ' ' . $sql;
        }

        return ['sql' => $sql, 'params' => $params->all()];
    }

    private function buildSelectSql(DialectInterface $dialect, ParameterBag $params, bool $includeOrderLimit): string
    {
        $columns = $this->renderColumns($dialect);
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $sql = 'SELECT ' . $distinct . $columns . ' FROM ' . $this->renderFrom($dialect, $params);

        $sql .= $this->renderJoins($dialect, $params);
        $sql .= $this->renderWhere($dialect, $params);
        $sql .= $this->renderGroupBy($dialect);
        $sql .= $this->renderHaving($dialect, $params);

        if ($includeOrderLimit) {
            $sql .= $this->renderOrderBy($dialect);
            $sql .= $this->renderLimit($dialect);
        }

        return $sql;
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

    private function renderFrom(DialectInterface $dialect, ParameterBag $params): string
    {
        if ($this->fromSubquery instanceof QueryInterface) {
            return $this->renderSubqueryTarget($dialect, $params, $this->fromSubquery, $this->alias);
        }

        return Identifier::quoteWithAlias($dialect, $this->table, $this->alias);
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

    private function renderJoins(DialectInterface $dialect, ParameterBag $params): string
    {
        if (empty($this->joins)) {
            return '';
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

    private function renderWhere(DialectInterface $dialect, ParameterBag $params): string
    {
        if (null === $this->where) {
            return '';
        }

        return ' WHERE ' . $this->where->toSql($dialect, $params);
    }

    private function renderGroupBy(DialectInterface $dialect): string
    {
        if (empty($this->groupBy)) {
            return '';
        }

        $groupParts = [];
        foreach ($this->groupBy as $group) {
            $groupParts[] = 'raw' === $group['type']
                ? $group['value']
                : Identifier::quote($dialect, $group['value']);
        }

        return ' GROUP BY ' . implode(', ', $groupParts);
    }

    private function renderHaving(DialectInterface $dialect, ParameterBag $params): string
    {
        if (null === $this->having) {
            return '';
        }

        return ' HAVING ' . $this->having->toSql($dialect, $params);
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

    private function renderLock(DialectInterface $dialect): string
    {
        if (null === $this->lock) {
            return '';
        }

        $dialectName = $dialect->name();
        if ('LOCK IN SHARE MODE' === $this->lock) {
            if ('mysql' !== $dialectName) {
                throw new RuntimeException('Lock clauses are not supported for ' . $dialectName . '.');
            }

            return ' ' . $this->lock;
        }

        if ('FOR UPDATE' === $this->lock && in_array($dialectName, ['mysql', 'pgsql'], true)) {
            return ' ' . $this->lock;
        }

        throw new RuntimeException('Lock clauses are not supported for ' . $dialectName . '.');
    }

    private function assertSetOperationsSupported(DialectInterface $dialect): void
    {
        if (empty($this->unions)) {
            return;
        }

        if ('mysql' !== $dialect->name()) {
            return;
        }

        foreach ($this->unions as $union) {
            if (!in_array($union['type'], ['INTERSECT', 'EXCEPT'], true)) {
                continue;
            }

            throw new RuntimeException('Set operation ' . $union['type'] . ' is not supported for ' . $dialect->name() . '.');
        }
    }

    private function normalizeDirection(?string $direction): ?string
    {
        if (null === $direction || '' === trim($direction)) {
            return null;
        }

        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
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

    private function clearDefaultColumns(): void
    {
        if (1 !== count($this->columns)) {
            return;
        }

        $column = $this->columns[0] ?? null;
        if (!is_array($column)) {
            return;
        }

        if (($column['type'] ?? null) !== 'raw' || ($column['value'] ?? null) !== '*') {
            return;
        }

        $this->columns = [];
    }

    private function selectAggregate(string $function, string $column, ?string $alias = null): self
    {
        $this->clearDefaultColumns();

        $function = strtolower(trim($function));
        if ('' === $function) {
            throw new RuntimeException('Aggregate function is required.');
        }

        $column = trim($column);
        if ('' === $column) {
            $column = '*';
        }

        $alias = null !== $alias ? trim($alias) : null;
        if ('' === $alias) {
            $alias = null;
        }

        $this->columns[] = [
            'type' => 'aggregate',
            'function' => $function,
            'value' => $column,
            'alias' => $alias,
        ];

        return $this;
    }

    private function renderColumns(DialectInterface $dialect): string
    {
        $columns = [];
        foreach ($this->columns as $column) {
            if ('raw' === $column['type']) {
                $columns[] = $column['value'];
                continue;
            }

            if ('alias' === $column['type']) {
                $columns[] = Identifier::quoteWithAlias($dialect, $column['value'], $column['alias'] ?? null);
                continue;
            }

            if ('aggregate' === $column['type']) {
                $function = strtoupper((string) $column['function']);
                $allowed = ['AVG', 'COUNT', 'MAX', 'MIN', 'SUM'];
                if (!in_array($function, $allowed, true)) {
                    throw new RuntimeException('Unsupported aggregate function: ' . $function);
                }
                $value = (string) ($column['value'] ?? '*');
                $target = '*' === $value ? '*' : Identifier::quote($dialect, $value);
                $expr = $function . '(' . $target . ')';
                $alias = $column['alias'] ?? null;
                if (is_string($alias) && '' !== $alias) {
                    $expr .= ' AS ' . $dialect->quoteIdentifier($alias);
                }
                $columns[] = $expr;
                continue;
            }

            $columns[] = Identifier::quote($dialect, $column['value']);
        }

        if (empty($columns)) {
            return '*';
        }

        return implode(', ', $columns);
    }

    private function renderJoinCondition(Condition|string $on, DialectInterface $dialect, ParameterBag $params): string
    {
        if ($on instanceof Condition) {
            return $on->toSql($dialect, $params);
        }

        return $on;
    }
}
