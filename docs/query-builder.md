# Query Builder

Build queries with PHP objects and execute them through `Connection`.

## Connect

Create a PDO connection and select its dialect:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;

$pdo = new \PDO('sqlite:app.sqlite');
$db = new Connection($pdo, DialectFactory::fromPdo($pdo));
```

The examples assume the referenced tables already exist.

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

Select queries also support computed columns (`selectAs()`, `selectRaw()`, `selectCount()` and related aggregates), joins and subquery joins, CTEs with `with()`, set operations (`union()`, `unionAll()`, `intersect()`, `except()`), grouping and `having()`, and row locking with `forUpdate()` or `lockInShareMode()`. See [driver limits](#driver-limits) for lock and set-operation restrictions.

## Insert rows

`values()` inserts one row, `rows()` inserts several, and `fromSelect()` inserts the result of a query:

```php
use arabcoders\database\Query\InsertQuery;

$affected = $db->execute(
    (new InsertQuery('todos'))->values([
        'title' => 'Review query builder',
        'status' => 'open',
    ])
);
```

Upserts use `onConflict([...])` or PostgreSQL's `onConflictConstraint('name')`, followed by `doUpdate([...])` or `doNothing()`. `upsert($updates, $conflictColumns)` performs both steps. `UpsertValue::inserted('column')` references the value proposed by the insert in an update payload. `doNothing()` and `returning([...])` require dialect support; see [driver limits](#driver-limits).

## Update and delete rows

Every update and delete needs an explicit `where(...)`. A missing condition throws a `RuntimeException`.

```php
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\DeleteQuery;
use arabcoders\database\Query\UpdateQuery;

$affected = $db->execute(
    (new UpdateQuery('todos'))
        ->values(['status' => 'done'])
        ->where(Condition::equals('id', 42))
);

$deleted = $db->execute(
    (new DeleteQuery('todos'))
        ->where(Condition::equals('id', 42))
);
```

Both write queries support CTEs and `returning()` where the dialect allows them. `orderBy()` and `limit()` on updates and deletes are not portable: PostgreSQL rejects them, and SQLite requires a build with update/delete limit support.

Joined updates and deletes differ by driver:

- MySQL supports joined update and delete syntax directly.
- PostgreSQL accepts only `INNER` joins.
- SQLite rejects joined updates and deletes.

## Transactions

`transaction()` commits when the callback returns and rolls back when it throws. Inside an active transaction it uses a savepoint:

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

`transactionRetry()` retries deadlocks, lock timeouts, and serialization failures, with 3 attempts by default. Pass a `$shouldRetry` callback to choose which errors to retry.

Other execution methods: `fetchAll()` and `fetchOne()` return rows, `execute()` returns an affected row count, `cursor()` and `chunked()` stream rows, and `execRaw()` and `fetchAllRaw()` run SQL with bound parameters.

PDO failures during execution are wrapped in `arabcoders\database\DatabaseException`.

## Conditions

`Condition` builds predicates for `where()`, `having()`, and join conditions. Common builders cover comparisons (`equals()`, `notEquals()`, `greaterThan()`, `between()`), null and set checks (`isNull()`, `in()`, `notIn()`), patterns (`like()`, `iLike()`, `startsWith()`, `regex()`), and column comparisons (`columnEquals()`, `columnCompare()`). Subquery predicates include `exists()` and `inSubquery()`. Combine predicates with `Condition::and()`, `or()`, `not()`, or `raw()`. `Condition::raw()` takes SQL text without bound parameters; don't pass user input to it.

JSON predicates use the `jsonPath*()` and `jsonArray*()` methods. PostgreSQL vector predicates are `vectorCosineDistance()`, `vectorL2Distance()`, and `vectorInnerProductDistance()`; each requires an operator and threshold. `fullText()` is available when the dialect reports full-text support.

SQLite regex predicates require a registered `REGEXP` function.

## Raw expressions and identifiers

`RawExpression` can be used in insert and update payloads. `UpdateQuery::setRaw()` is a shortcut for one raw update expression. `Identifier` quotes identifiers, including dotted names and aliases.

Use raw SQL only for expressions the query API does not represent. Keep user-provided values parameterized.

## Macros

Register project-specific query helpers with `macro()`:

```php
use arabcoders\database\Query\SelectQuery;

SelectQuery::macro('recent', function (int $limit = 10) {
    return $this->orderBy('created_at', 'DESC')->limit($limit);
});

$query = (new SelectQuery('events'))->recent(25);
```

## Query caching

Call `cache($key, $ttl)` on a query and set a PSR-16 cache with `Connection::setCache()`. `fetchAll()` and `fetchOne()` read and write the cache. `fetchAllCached()` and `fetchOneCached()` accept an explicit key and TTL.

## Driver limits

- `INTERSECT` and `EXCEPT` are rejected on MySQL.
- `FOR UPDATE` works on MySQL and PostgreSQL. `LOCK IN SHARE MODE` is MySQL-only.
- `RETURNING` is enabled on PostgreSQL and SQLite. For MySQL, check the dialect's `supportsReturning()`; MariaDB is rejected.
- `DO NOTHING` upserts work on PostgreSQL and SQLite, not MySQL.
- Subqueries cannot contain `WITH`.

See [dialects](dialects.md) for driver selection and custom dialects.
