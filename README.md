# Database Package

PDO queries, ORM, schema migrations, and seeders for PHP.

## Install

```bash
composer require arabcoders/database
```

## Requirements

- PHP 8.5 or newer.
- A PDO driver for `mysql`, `pgsql`, or `sqlite`.

## Guides

- [Query builder](docs/query-builder.md)
- [ORM](docs/orm.md)
- [Schema and migrations](docs/schema-migrations.md)
- [Seeding](docs/seeding.md)
- [Database dialects](docs/dialects.md)

## Connect and run a query

Query an existing `todos` table:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;

$pdo = new \PDO('sqlite:app.sqlite');
$db = new Connection($pdo, DialectFactory::fromPdo($pdo));

$rows = $db->fetchAll(
    (new SelectQuery('todos'))
        ->select(['id', 'title'])
        ->where(Condition::equals('status', 'open'))
        ->orderBy('id', 'DESC')
        ->limit(20)
);
```
