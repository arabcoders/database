# Query Builder

The query builder composes SQL through immutable-like fluent objects that compile into:

- SQL string
- bound parameter map

All query objects implement `arabcoders\database\Query\QueryInterface`.

## Main Types

- `SelectQuery`
- `InsertQuery`
- `UpdateQuery`
- `DeleteQuery`
- `Condition`

`Connection` executes query objects and handles prepared statements/binding.

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

- projection (`select`, `selectAs`, `selectRaw`, aggregates)
- `JOIN` and `joinSubquery`
- CTEs with `with(name, query, recursive: bool)`
- set operations (`union`, `unionAll`, `intersect`, `except`)
- `where`, `groupBy`, `having`, `orderBy`, `limit`
- lock clauses (`forUpdate`, `lockInShareMode`)

Notes:

- `INTERSECT` and `EXCEPT` are rejected for MySQL.
- lock clauses are dialect-specific:
  - `FOR UPDATE`: MySQL and PostgreSQL
  - `LOCK IN SHARE MODE`: MySQL only
- subqueries cannot include `WITH` (enforced by `QueryCompiler`).

## Condition API

`Condition` powers `WHERE`, `HAVING`, and join predicates.

Core builders:

- comparisons: `equals`, `notEquals`, `greaterThan`, `between`, etc.
- null checks: `isNull`, `isNotNull`
- set checks: `in`, `notIn`
- pattern checks: `like`, `iLike`, `startsWith`, `endsWith`, `regex`
- boolean composition: `and`, `or`, `not`
- column comparisons: `columnEquals`, `columnCompare`
- subqueries: `exists`, `inSubquery`, `notExists`, `notInSubquery`

Advanced builders:

- JSON path/value operations (`jsonPath*`, `jsonArray*`)
- vector distance operations for PostgreSQL (`vectorCosineDistance`, `vectorL2Distance`, `vectorInnerProductDistance`)
- full text predicate (`fullText`)

Dialect behavior:

- `iLike` uses native `ILIKE` in PostgreSQL and `LOWER(...) LIKE LOWER(...)` fallback otherwise.
- regex operators are generated per dialect.
- vector predicates throw unless dialect is PostgreSQL.

## InsertQuery

Insert modes:

- single row: `values([...])`
- multi row: `rows([[...], [...]])`
- `INSERT ... SELECT`: `fromSelect([...], $query)`

Upsert options:

- `onConflict([...])`
- `onConflictConstraint('constraint_name')` (PostgreSQL)
- `doUpdate([...])`
- `doNothing()`
- shortcut: `upsert($updates, $conflictColumns, $constraint)`

Use `UpsertValue::inserted('column')` in update payloads when you need the inserted value expression.

Returning:

- `returning([...])` is available only if the dialect reports support.

## UpdateQuery and DeleteQuery

Both require an explicit `where(...)`; missing predicates throw a runtime error.

Supported features:

- CTEs (`with`)
- joins (dialect-limited)
- `orderBy` and `limit`
- optional `returning`

Join support details:

- MySQL: join syntax supported directly.
- PostgreSQL: translated into `FROM`/`USING` forms with constraints.
- SQLite: joined update/delete are not supported.

## Raw SQL Fragments

- `RawExpression` can be used in insert/update payloads.
- `Identifier` safely quotes identifiers (including dotted names and aliases).

Use raw expressions intentionally and keep values parameterized when possible.

## Query Macros

All query classes use `Macroable`.

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

`Connection` consumes these values when a cache backend is configured via `setCache()`.

## Execution Through Connection

`Connection` methods:

- `fetchAll($query)`
- `fetchOne($query)`
- `execute($query)`
- `cursor($query)`
- `chunked($query, $size)`

Plus explicit-key cache wrappers:

- `fetchAllCached($query, $key, $ttl)`
- `fetchOneCached($query, $key, $ttl)`
