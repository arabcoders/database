# Database Package

`arabcoders/database` is a standalone PHP database package for applications that want predictable PDO access, explicit SQL generation, and optional ORM, schema, and seeding tools.

You can use the whole package or adopt only the parts you need:

- `Connection` and `ConnectionManager` handle execution, transactions, retries, and multi-connection setups.
- `Query/*` provides builders for `SELECT`, `INSERT`, `UPDATE`, and `DELETE` statements.
- `Orm/*` maps attribute-based entities to repositories and relation loaders.
- `Schema/*` covers schema definitions, introspection, diffing, and SQL rendering.
- `Seeder/*` and `Commands/SeederService.php` support seeder discovery and execution.

## Core Principles

- The package favors explicit behavior. Query objects compile into SQL and bound parameters you can inspect.
- Entities remain regular PHP objects. Repositories hydrate public mapped properties and run named operations.
- SQL generation stays dialect-aware so the same API can target MySQL, PostgreSQL, or SQLite.
- Each module can be used independently, so you do not need to adopt the full stack.

## Package Layout

- `Attributes/` contains attributes for schema, ORM, migrations, and seeders.
- `Commands/` contains higher-level services for migration and seeder workflows.
- `Dialect/` contains DML dialects used by the query builder and `Connection`.
- `Orm/` contains metadata factories, repositories, relation loading, and relation writes.
- `Query/` contains query objects, conditions, compilers, and parameter handling.
- `Schema/` contains schema registries, introspection, diffing, SQL rendering, and blueprints.
- `Seeder/` contains seeder registries, dependency resolution, execution, and history tracking.
- `Transformer/` contains value transform helpers used during ORM reads and writes.
- `Validator/` contains validation attributes and rules used by the ORM.

## Requirements

- PHP 8.4+.
- A PDO driver for `mysql`, `pgsql`, or `sqlite`.

## Quick Start

The example below creates a connection, runs a query, and sets up an `OrmManager` for repository access.

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Orm\OrmManager;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo, DialectFactory::fromPdo($pdo));

$rows = $connection->fetchAll(
    (new SelectQuery('todos'))
        ->select(['id', 'title'])
        ->where(Condition::equals('status', 'open'))
        ->orderBy('id', 'DESC')
        ->limit(20)
);

$connections = new ConnectionManager();
$connections->register('default', $connection);

$orm = new OrmManager($connections);
// If you only have one connection:
// $orm = OrmManager::fromConnection($connection);
```

## Additional Documentation

- Query builder: `docs/query-builder.md`
- ORM: `docs/orm.md`
- Schema and migrations: `docs/schema-migrations.md`
- Seeding: `docs/seeding.md`
- Dialects and extension points: `docs/dialects.md`

## Namespaces

When integrating the package, you will usually work with these namespaces:

- `arabcoders\database\*`
- `arabcoders\database\Attributes\*`

`SchemaRegistry`, `MigrationRegistry`, and `SeederRegistry` use `arabcoders\database\Scanner\Attributes` for attribute discovery.
