# Database Package

This directory contains a standalone database toolkit built around explicit SQL generation.

The package is split into small modules that you can adopt independently:

- `Connection` and `ConnectionManager` for execution, transactions, and retries.
- Query builder (`Query/*`) for DML (`SELECT`, `INSERT`, `UPDATE`, `DELETE`).
- ORM (`Orm/*`) for attribute-based entity metadata and repositories.
- Schema and migration tooling (`Schema/*`).
- Seeder tooling (`Seeder/*`, `Commands/SeederService.php`).

## Core Principles

- Explicit behavior first. Query objects compile to SQL and bound params.
- No hidden ORM magic. Repositories map public properties and run known operations.
- Dialect-aware output. SQL generation is adapted per database driver.
- Composable modules. Use only query builder, only schema tools, or the whole stack.

## Package Layout

- `Attributes/` - attributes for schema, ORM, migrations, and seeders.
- `Commands/` - service-level APIs for migration and seeder workflows.
- `Dialect/` - DML dialects used by query builder and `Connection`.
- `Orm/` - metadata factory, repositories, relation loading, relation writes.
- `Query/` - query objects, conditions, compiler, parameter bag.
- `Schema/` - schema registry, introspection, diffing, SQL rendering, blueprints.
- `Seeder/` - seeder registry, dependency resolver, executor, execution history.
- `Transformer/` - value transform attributes/helpers used by ORM hydration/writes.
- `Validator/` - validation attributes/helpers used by ORM.

## Requirements

- PHP 8.4+
- PDO driver: `mysql`, `pgsql`, or `sqlite`

## Quick Start

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
// Alternative for single connection use cases:
// $orm = OrmManager::fromConnection($connection);
```

## Additional Documentation

- Query builder: `docs/query-builder.md`
- ORM: `docs/orm.md`
- Schema and migrations: `docs/schema-migrations.md`
- Seeding: `docs/seeding.md`
- Dialects and extension points: `docs/dialects.md`

## Namespace Notes

Keep these namespaces together when consuming the package:

- `arabcoders\database\*`
- `arabcoders\database\Attributes\*`

`SchemaRegistry`, `MigrationRegistry`, and `SeederRegistry` rely on `arabcoders\database\Scanner\Attributes` for attribute discovery.
