<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;
use RuntimeException;

final class InsertQuery implements QueryInterface, CacheableQueryInterface
{
    use Macroable;

    /**
     * @var array<string,mixed>
     */
    private array $values = [];

    /**
     * @var array<int,array<string,mixed>>
     */
    private array $rows = [];

    /**
     * @var array<int,string>
     */
    private array $selectColumns = [];

    private ?QueryInterface $selectQuery = null;

    /**
     * @var array<int,array{name:string,query:QueryInterface,recursive:bool}>
     */
    private array $with = [];

    private bool $withRecursive = false;

    /**
     * @var array<int,string|RawExpression>
     */
    private array $returning = [];

    private ?string $upsertAction = null;

    /**
     * @var array<int,string>
     */
    private array $upsertConflictColumns = [];

    private ?string $upsertConstraint = null;

    /**
     * @var array<string,mixed>
     */
    private array $upsertUpdates = [];

    private ?string $cacheKey = null;
    private ?int $cacheTtl = null;

    public function __construct(
        private string $table,
    ) {
        $this->table = TableResolver::resolve($table);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function values(array $values): self
    {
        $this->values = $values;
        $this->rows = [];
        $this->selectColumns = [];
        $this->selectQuery = null;

        return $this;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;
        $this->values = [];
        $this->selectColumns = [];
        $this->selectQuery = null;

        return $this;
    }

    /**
     * @param array<int,string> $columns
     */
    public function fromSelect(array $columns, QueryInterface $query): self
    {
        $this->selectColumns = $columns;
        $this->selectQuery = $query;
        $this->values = [];
        $this->rows = [];

        return $this;
    }

    /**
     * Attach a common table expression used by this insert query.
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
     * @param array<int,string|RawExpression> $columns
     */
    public function returning(array $columns): self
    {
        $this->returning = $columns;

        return $this;
    }

    /**
     * @param array<int,string> $columns
     */
    public function onConflict(array $columns): self
    {
        $this->upsertConflictColumns = $columns;
        $this->upsertConstraint = null;

        return $this;
    }

    /**
     * Define a named unique/exclusion constraint as the upsert conflict target.
     *
     * @param string $constraint Constraint name.
     * @return self
     * @throws RuntimeException If the constraint name is empty.
     */
    public function onConflictConstraint(string $constraint): self
    {
        $constraint = trim($constraint);
        if ('' === $constraint) {
            throw new RuntimeException('Conflict constraint is required.');
        }

        $this->upsertConstraint = $constraint;
        $this->upsertConflictColumns = [];

        return $this;
    }

    /**
     * @param array<string,mixed> $updates
     */
    public function doUpdate(array $updates): self
    {
        $this->upsertAction = 'update';
        $this->upsertUpdates = $updates;

        return $this;
    }

    public function doNothing(): self
    {
        $this->upsertAction = 'nothing';
        $this->upsertUpdates = [];

        return $this;
    }

    /**
     * @param array<string,mixed> $updates
     * @param array<int,string> $conflictColumns
     */
    public function upsert(array $updates, array $conflictColumns = [], ?string $constraint = null): self
    {
        if (null !== $constraint) {
            $this->onConflictConstraint($constraint);
        } elseif (!empty($conflictColumns)) {
            $this->onConflict($conflictColumns);
        }

        return $this->doUpdate($updates);
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
     * Compile the insert query into SQL and bound parameters.
     *
     * @param DialectInterface $dialect SQL dialect used to render identifiers and clauses.
     * @return array{sql:string,params:array<string,mixed>}
     * @throws RuntimeException If no insert source is provided.
     * @throws RuntimeException If a dialect does not support a requested insert option.
     */
    public function toSql(DialectInterface $dialect): array
    {
        $params = new ParameterBag();

        $sql = '';
        if ($this->selectQuery instanceof QueryInterface) {
            $sql = $this->buildInsertFromSelect($dialect, $params);
        } elseif (!empty($this->rows)) {
            $sql = $this->buildInsertValues($dialect, $params, $this->rows);
        } elseif (!empty($this->values)) {
            $sql = $this->buildInsertValues($dialect, $params, [$this->values]);
        } else {
            throw new RuntimeException('Insert values are required.');
        }

        $sql .= $this->renderUpsert($dialect, $params);

        $sql .= $this->renderReturning($dialect);

        $withSql = $this->renderWith($dialect, $params);
        if ('' !== $withSql) {
            $sql = $withSql . ' ' . $sql;
        }

        return ['sql' => $sql, 'params' => $params->all()];
    }

    private function buildInsertFromSelect(DialectInterface $dialect, ParameterBag $params): string
    {
        if (empty($this->selectColumns)) {
            throw new RuntimeException('Insert columns are required for select inserts.');
        }

        $columns = array_map(static fn(string $column) => Identifier::quote($dialect, $column), $this->selectColumns);
        $selectSql = QueryCompiler::compile($this->selectQuery, $dialect, $params, false);

        return 'INSERT INTO ' . Identifier::quote($dialect, $this->table) . ' (' . implode(', ', $columns) . ') ' . $selectSql;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function buildInsertValues(DialectInterface $dialect, ParameterBag $params, array $rows): string
    {
        $columns = array_keys($rows[0]);
        if (empty($columns)) {
            throw new RuntimeException('Insert values are required.');
        }

        foreach ($rows as $row) {
            $this->assertRowColumns($columns, $row);
        }

        $quotedColumns = array_map(static fn(string $column) => Identifier::quote($dialect, $column), $columns);
        $groups = [];
        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($value instanceof RawExpression) {
                    $placeholders[] = $value->sql();
                    continue;
                }
                $placeholders[] = $params->add($value);
            }
            $groups[] = '(' . implode(', ', $placeholders) . ')';
        }

        return (
            'INSERT INTO '
            . Identifier::quote($dialect, $this->table)
            . ' ('
            . implode(', ', $quotedColumns)
            . ')'
            . ' VALUES '
            . implode(', ', $groups)
        );
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,mixed> $row
     */
    private function assertRowColumns(array $columns, array $row): void
    {
        $rowColumns = array_keys($row);
        $missing = array_diff($columns, $rowColumns);
        $extra = array_diff($rowColumns, $columns);
        if (!empty($missing) || !empty($extra)) {
            throw new RuntimeException('Insert rows must share the same columns.');
        }
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

    private function renderUpsert(DialectInterface $dialect, ParameterBag $params): string
    {
        if (null === $this->upsertAction) {
            return '';
        }

        if ('nothing' === $this->upsertAction && !$dialect->supportsUpsertDoNothing()) {
            throw new RuntimeException('Upsert do nothing is not supported for ' . $dialect->name() . '.');
        }

        if ('update' === $this->upsertAction && empty($this->upsertUpdates)) {
            throw new RuntimeException('Upsert update values are required.');
        }

        return match ($dialect->name()) {
            'sqlite' => $this->renderSqliteUpsert($dialect, $params),
            'mysql' => $this->renderMysqlUpsert($dialect, $params),
            'pgsql' => $this->renderPostgresUpsert($dialect, $params),
            default => throw new RuntimeException('Upsert is not supported for ' . $dialect->name() . '.'),
        };
    }

    private function renderSqliteUpsert(DialectInterface $dialect, ParameterBag $params): string
    {
        if (null !== $this->upsertConstraint) {
            throw new RuntimeException('SQLite does not support conflict constraint names.');
        }

        $target = '';
        if (!empty($this->upsertConflictColumns)) {
            $columns = array_map(static fn(string $column) => Identifier::quote($dialect, $column), $this->upsertConflictColumns);
            $target = ' (' . implode(', ', $columns) . ')';
        }

        if ('update' === $this->upsertAction && '' === $target) {
            throw new RuntimeException('SQLite upsert update requires conflict columns.');
        }

        if ('nothing' === $this->upsertAction) {
            return ' ON CONFLICT' . $target . ' DO NOTHING';
        }

        return ' ON CONFLICT' . $target . ' DO UPDATE SET ' . $this->renderUpsertAssignments($dialect, $params);
    }

    private function renderMysqlUpsert(DialectInterface $dialect, ParameterBag $params): string
    {
        return ' ON DUPLICATE KEY UPDATE ' . $this->renderUpsertAssignments($dialect, $params);
    }

    private function renderPostgresUpsert(DialectInterface $dialect, ParameterBag $params): string
    {
        $target = '';
        if (null !== $this->upsertConstraint) {
            $constraint = trim($this->upsertConstraint);
            if ('' === $constraint) {
                throw new RuntimeException('Conflict constraint is required.');
            }
            $target = ' ON CONSTRAINT ' . Identifier::quote($dialect, $constraint);
        } elseif (!empty($this->upsertConflictColumns)) {
            $columns = array_map(static fn(string $column) => Identifier::quote($dialect, $column), $this->upsertConflictColumns);
            $target = ' (' . implode(', ', $columns) . ')';
        }

        if ('update' === $this->upsertAction && '' === $target) {
            throw new RuntimeException('Postgres upsert update requires conflict columns or a constraint.');
        }

        if ('nothing' === $this->upsertAction) {
            return ' ON CONFLICT' . $target . ' DO NOTHING';
        }

        return ' ON CONFLICT' . $target . ' DO UPDATE SET ' . $this->renderUpsertAssignments($dialect, $params);
    }

    private function renderUpsertAssignments(DialectInterface $dialect, ParameterBag $params): string
    {
        $parts = [];
        foreach ($this->upsertUpdates as $column => $value) {
            if ($value instanceof RawExpression) {
                $expression = $value->sql();
            } elseif ($value instanceof UpsertValue) {
                $expression = $dialect->renderUpsertInsertValue($value->column());
            } else {
                $expression = $params->add($value);
            }

            $parts[] = Identifier::quote($dialect, $column) . ' = ' . $expression;
        }

        return implode(', ', $parts);
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
