# Query Builder

The query builder describes SQL statements as PHP objects. `Connection` compiles each object, prepares the SQL, and binds its parameters.

## Connect

Create a PDO connection and select its dialect before building queries:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;

$pdo = new \PDO('sqlite::memory:');
$db = new Connection($pdo, DialectFactory::fromPdo($pdo));
```

All query objects implement `arabcoders\database\Query\QueryInterface`. The main types are `SelectQuery`, `InsertQuery`, `UpdateQuery`, `DeleteQuery`, and `Condition`.

## Read rows

Use `SelectQuery` with `fetchAll()` or `fetchOne()`:

```php
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;

$query = (new SelectQuery('todos'))
    ->select(['id', 'title', 'created_at'])
    ->where(Condition::equals('status', 'open'))
    ->orderBy('created_at', 'DESC')
    ->limit(25);

$rows = $db->fetchAll($query);
$first = $db->fetchOne($query);
```

`SelectQuery` supports projections through `select`, `selectAs`, `selectRaw`, and aggregate helpers. It also supports joins, `joinSubquery(...)`, `fromSubquery(...)`, CTEs through `with(name, query, recursive: bool)`, `union`, `unionAll`, `intersect`, `except`, `where`, `groupBy`, `having`, `orderBy`, `limit`, `forUpdate()`, and `lockInShareMode()`.

## Insert rows

`values()` inserts one row. Use `rows()` for multiple rows, or `fromSelect()` for `INSERT ... SELECT`:

```php
use arabcoders\database\Query\InsertQuery;

$affected = $db->execute(
    (new InsertQuery('todos'))->values([
        'title' => 'Review query builder',
        'status' => 'open',
    ])
);
```

Insert queries also support `onConflict([...])`, `onConflictConstraint('constraint_name')` for PostgreSQL, `doUpdate([...])`, `doNothing()`, and `upsert($updates, $conflictColumns, $constraint)`. Use `UpsertValue::inserted('column')` for an inserted-value expression in an upsert update payload. `returning([...])` works only when the active dialect reports support.

## Update rows safely

**Safety rule:** every update and delete must have an explicit `where(...)` condition. The package throws instead of generating an unrestricted statement when the condition is missing.

```php
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\UpdateQuery;

$affected = $db->execute(
    (new UpdateQuery('todos'))
        ->values(['status' => 'done'])
        ->where(Condition::equals('id', 42))
);
```

`UpdateQuery` also supports `setRaw()`, CTEs through `with(...)`, joins where the dialect allows them, `orderBy(...)`, `limit(...)`, and optional `returning(...)`.

## Delete rows safely

Apply the same explicit predicate rule to deletes:

```php
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\DeleteQuery;

$affected = $db->execute(
    (new DeleteQuery('todos'))
        ->where(Condition::equals('id', 42))
);
```

`DeleteQuery` supports CTEs through `with(...)`, joins where the dialect allows them, `orderBy(...)`, `limit(...)`, and optional `returning(...)`.

Join behavior differs by database:

- MySQL supports joined update and delete syntax directly.
- PostgreSQL translates joined operations into `FROM` and `USING` forms where possible, and only supports `INNER` joins for these operations.
- SQLite does not support joined updates or deletes.

## Transactions and execution

`transaction()` commits when the callback succeeds and rolls back when it throws. If a transaction is already active, it uses a savepoint:

```php
$db->transaction(function () use ($db): void {
    $db->execute(
        (new UpdateQuery('todos'))
            ->values(['status' => 'done'])
            ->where(Condition::equals('id', 42))
    );

    $db->execute(
        (new InsertQuery('audit_log'))->values([
            'todo_id' => 42,
            'action' => 'completed',
        ])
    );
});
```

Use `transactionRetry($callback, $maxAttempts = 3, $shouldRetry = null, $baseDelayMs = 0)` when retry behavior is required. Other execution methods are:

- `fetchAll($query)` for all rows.
- `fetchOne($query)` for the first row or `null`.
- `execute($query)` for the affected row count.
- `cursor($query)` for a row generator.
- `chunked($query, $size)` for a generator of fixed-size row arrays.
- `execRaw($sql, $params = [])` and `fetchAllRaw($sql, $params = [])` for raw SQL.

Execution failures throw `arabcoders\database\DatabaseException`.

## Conditions

`Condition` builds `WHERE`, `HAVING`, and join predicates. Common builders are:

- Comparisons: `equals`, `notEquals`, `greaterThan`, `greaterOrEqual`, `lessThan`, `lessOrEqual`, and `between`.
- Null and set checks: `isNull`, `isNotNull`, `in`, and `notIn`.
- Patterns: `like`, `notLike`, `iLike`, `notILike`, `startsWith`, `endsWith`, `regex`, and `notRegex`.
- Column comparisons: `columnEquals`, `columnNotEquals`, `columnGreaterThan`, `columnGreaterOrEqual`, `columnLessThan`, `columnLessOrEqual`, and `columnCompare`.
- Subqueries: `exists`, `notExists`, `inSubquery`, and `notInSubquery`.
- Composition: `and`, `or`, `not`, and `raw`.

## Advanced predicates

JSON predicates include `jsonPathEquals`, `jsonPathNotEquals`, `jsonPathContains`, `jsonPathNotContains`, `jsonPathExists`, `jsonPathNotExists`, `jsonPathIn`, `jsonPathNotIn`, `jsonArrayContains`, and `jsonArrayNotContains`.

PostgreSQL vector predicates are `vectorCosineDistance`, `vectorL2Distance`, and `vectorInnerProductDistance`. Full-text predicates use `fullText`.

Dialect behavior includes:

- `iLike` uses native `ILIKE` on PostgreSQL and falls back to `LOWER(...) LIKE LOWER(...)` elsewhere.
- Regex operators are rendered per dialect.
- JSON path and array predicates are implemented for MySQL, PostgreSQL, and SQLite.
- Vector predicates throw unless the active dialect is PostgreSQL.
- Full-text predicates throw when the active dialect does not report full-text support.

## Raw expressions and identifiers

`RawExpression` can be used in insert and update payloads. `UpdateQuery::setRaw()` is a shortcut for one raw update expression. `Identifier` safely quotes identifiers, including dotted names and aliases.

Use raw SQL only for expressions the query API does not represent. Keep user-provided values parameterized.

## Macros

All query classes use `Macroable`, so you can add project-specific helpers:

```php
use arabcoders\database\Query\SelectQuery;

SelectQuery::macro('recent', function (int $limit = 10) {
    return $this->orderBy('created_at', 'DESC')->limit($limit);
});

$query = (new SelectQuery('events'))->recent(25);
```

## Query caching

Query classes implement `CacheableQueryInterface` and expose `cache($key, $ttl = null)`, `cacheKey()`, and `cacheTtl()`. Configure a PSR simple cache backend with `Connection::setCache()` before executing cache-aware queries.

For explicit cache keys, use `fetchAllCached($query, $key, $ttl)` or `fetchOneCached($query, $key, $ttl)`.

## Driver differences

- `INTERSECT` and `EXCEPT` are rejected for MySQL.
- `FOR UPDATE` works on MySQL and PostgreSQL.
- `LOCK IN SHARE MODE` is MySQL-only.
- `RETURNING` depends on the active dialect. MySQL support depends on server version and doesn't apply to MariaDB. PostgreSQL and SQLite support `RETURNING`.
- PostgreSQL and SQLite support `DO NOTHING` upsert mode.
- Subqueries cannot include `WITH`; `QueryCompiler` enforces this rule.

See [dialects and extensibility](dialects.md) for DML and DDL dialect APIs, feature flags, registration, and schema-specific driver behavior.
