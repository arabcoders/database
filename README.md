# Database Package

`arabcoders/database` is a standalone PHP database package for PDO access, explicit SQL generation, and optional ORM, schema, migration, and seeding tools.

## Install

```bash
composer require arabcoders/database
```

## Requirements

- PHP 8.5 or newer.
- A PDO driver for `mysql`, `pgsql`, or `sqlite`.

## Choose a workflow

- Need `SELECT`, `INSERT`, `UPDATE`, or `DELETE` statements? Start with the [query builder](docs/query-builder.md).
- Need entities, repositories, relations, or validation? Read the [ORM guide](docs/orm.md).
- Need model-backed schema changes or migration runs? Read [schema and migrations](docs/schema-migrations.md).
- Need baseline data, fixtures, or repeatable setup? Read the [seeding guide](docs/seeding.md).

## Connect and run a query

`DialectFactory::fromPdo()` selects the query dialect for the active PDO driver. Query values are bound by `Connection`.

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;

$pdo = new \PDO('sqlite::memory:');
$db = new Connection($pdo, DialectFactory::fromPdo($pdo));

$rows = $db->fetchAll(
    (new SelectQuery('todos'))
        ->select(['id', 'title'])
        ->where(Condition::equals('status', 'open'))
        ->orderBy('id', 'DESC')
        ->limit(20)
);
```

## Capabilities

- Query objects for selects, writes, joins, predicates, subqueries, CTEs, set operations, locking, upserts, and `RETURNING` where the driver supports it.
- Prepared query execution through `Connection`, including row fetching, cursors, chunked results, raw SQL, transactions, nested savepoints, and retry behavior.
- Attribute-defined entities with repositories, identity maps, soft deletes, relations, eager loading, lifecycle hooks, transforms, and validation.
- Declarative schema definitions, live-schema introspection, diffs, reversible SQL, blueprint migrations, migration checksums, locks, repair, squashing, and SQLite rebuild handling.
- Attribute-discovered seeders with dependencies, filters, run modes, transaction modes, dry runs, and execution history.

For driver behavior, feature flags, and custom SQL or schema dialects, see [dialects and extensibility](docs/dialects.md).
