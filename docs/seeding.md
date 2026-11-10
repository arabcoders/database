# Seeding

Use seeders for baseline data and fixtures.

## Define a seeder

```php
<?php

declare(strict_types=1);

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'base_users', tags: ['base'], groups: ['dev'], mode: 'once')]
final class BaseUsersSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execute(
            (new InsertQuery('users'))->values([
                'email' => 'admin@example.test',
                'status' => 'active',
            ])
        );
    }
}
```

Seeder names must be unique, ignoring case. The default seeder mode is `always`.

## Run seeders

```php
use arabcoders\database\Commands\SeederRequest;
use arabcoders\database\Commands\SeederService;
use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederTransactionMode;

$service = new SeederService($pdo, __DIR__ . '/seeders', $container);

$preview = $service->run(new SeederRequest());
$result = $service->run(new SeederRequest(
    dryRun: false,
    mode: SeederRunMode::AUTO,
    transactionMode: SeederTransactionMode::PER_SEEDER,
));
```

Requests default to a dry run. Set `dryRun: false` to execute seeders. Inspect `$result->executionEntries()` for run outcomes.

Use `list()` to see available seeders without running them.

## Filtering and dry runs

`classFilter` matches a seeder name prefix, ignoring case, and must identify exactly one seeder. Use `tag` and `group` for exact matches; both must match when supplied.

Dry runs show pending and skipped seeders without executing them or creating history tables.

`dependsOn` lists seeders that must run first. Required dependencies are included regardless of filters. Unknown names and dependency cycles throw.

## Run modes and transactions

`SeederRunMode` defines four modes:

- `auto` uses the mode declared on each seeder.
- `once` skips seeders that have already succeeded.
- `always` runs the seeder regardless of previous runs.
- `rebuild` deletes the seeder's history rows before running it again.

`SeederTransactionMode` controls transaction scope:

- `per-seeder`, the default, wraps each seeder in a transaction.
- `per-run` wraps the whole run in one transaction.
- `none` adds no transaction.

Failed attempts are recorded. With `per-run`, a failure rolls back all seeders in that run.
