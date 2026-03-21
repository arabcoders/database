# Query Builder

Use the query builder when you want explicit SQL generation without assembling query strings by hand. Query objects describe the statement you want, and the connection layer compiles them into SQL plus a bound parameter map.

All query objects implement `arabcoders\database\Query\QueryInterface`. Execute them through `Connection`, which handles prepared statements and parameter binding.

## Main Types

The main query builder types are:

- `SelectQuery`
- `InsertQuery`
- `UpdateQuery`
- `DeleteQuery`
- `Condition`

## Basic Usage

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;

$pdo = new PDO('sqlite::memory:');
$db = new Connection($pdo, DialectFactory::fromPdo($pdo));

$query = (new SelectQuery('todos'))
    ->select(['id', 'title', 'created_at'])
    ->where(Condition::equals('status', 'open'))
    ->orderBy('created_at', 'DESC')
    ->limit(25);

$rows = $db->fetchAll($query);
```

## SelectQuery

`SelectQuery` supports:

- Projections through `select`, `selectAs`, `selectRaw`, and aggregate helpers.
- `JOIN` clauses and `joinSubquery(...)`.
- CTEs with `with(name, query, recursive: bool)`.
- Set operations such as `union`, `unionAll`, `intersect`, and `except`.
- `where`, `groupBy`, `having`, `orderBy`, and `limit` clauses.
- Lock clauses such as `forUpdate()` and `lockInShareMode()`.

A few database-specific rules apply:

- `INTERSECT` and `EXCEPT` are rejected for MySQL.
- Lock clauses are dialect-specific: `FOR UPDATE` works on MySQL and PostgreSQL, while `LOCK IN SHARE MODE` is MySQL-only.
- Subqueries cannot include `WITH`; this is enforced by `QueryCompiler`.

## Condition API

`Condition` builds `WHERE`, `HAVING`, and join predicates.

Common builders include:

- Comparison helpers such as `equals`, `notEquals`, `greaterThan`, and `between`.
- Null checks such as `isNull` and `isNotNull`.
- Set checks such as `in` and `notIn`.
- Pattern helpers such as `like`, `iLike`, `startsWith`, `endsWith`, and `regex`.
- Boolean composition through `and`, `or`, and `not`.
- Column comparisons such as `columnEquals` and `columnCompare`.
- Subquery helpers such as `exists`, `inSubquery`, `notExists`, and `notInSubquery`.

Advanced builders include:

- JSON path and array predicates (`jsonPath*`, `jsonArray*`).
- PostgreSQL vector distance helpers (`vectorCosineDistance`, `vectorL2Distance`, `vectorInnerProductDistance`).
- Full-text search predicates through `fullText`.

Dialect behavior differs in a few places:

- `iLike` uses native `ILIKE` on PostgreSQL and falls back to `LOWER(...) LIKE LOWER(...)` elsewhere.
- Regex operators are rendered per dialect.
- Vector predicates throw unless the active dialect is PostgreSQL.

## InsertQuery

`InsertQuery` supports several write styles:

- Single-row inserts with `values([...])`.
- Multi-row inserts with `rows([[...], [...]])`.
- `INSERT ... SELECT` statements with `fromSelect([...], $query)`.

Upsert helpers include:

- `onConflict([...])`
- `onConflictConstraint('constraint_name')` for PostgreSQL
- `doUpdate([...])`
- `doNothing()`
- `upsert($updates, $conflictColumns, $constraint)` as a shortcut

Use `UpsertValue::inserted('column')` in an upsert update payload when you need the inserted-value expression.

`returning([...])` is available only when the active dialect reports support.

## UpdateQuery and DeleteQuery

Both `UpdateQuery` and `DeleteQuery` require an explicit `where(...)`. If you omit the predicate, the package throws a runtime error instead of generating an unrestricted statement.

Supported features include:

- CTEs through `with(...)`
- Join support where the active dialect allows it
- `orderBy(...)` and `limit(...)`
- Optional `returning(...)`

Join behavior differs by database:

- MySQL supports joined update and delete syntax directly.
- PostgreSQL translates joined operations into `FROM` and `USING` forms where possible.
- SQLite does not support joined updates or deletes.

## Raw SQL Fragments

`RawExpression` can be used in insert and update payloads, and `Identifier` safely quotes identifiers, including dotted names and aliases.

Use raw SQL intentionally and keep user-provided values parameterized whenever possible.

## Query Macros

All query classes use `Macroable`, so you can add project-specific helpers.

```php
<?php

use arabcoders\database\Query\SelectQuery;

SelectQuery::macro('recent', function (int $limit = 10) {
    return $this->orderBy('created_at', 'DESC')->limit($limit);
});

$query = (new SelectQuery('events'))->recent(25);
```

## Caching Hooks

Query classes implement `CacheableQueryInterface` and expose:

- `cache($key, $ttl = null)`
- `cacheKey()`
- `cacheTtl()`

When you configure a cache backend with `Connection::setCache()`, the connection uses those values for cached query execution.

## Executing Queries Through Connection

`Connection` provides these execution methods:

- `fetchAll($query)`
- `fetchOne($query)`
- `execute($query)`
- `cursor($query)`
- `chunked($query, $size)`

If you prefer explicit cache keys, use:

- `fetchAllCached($query, $key, $ttl)`
- `fetchOneCached($query, $key, $ttl)`
