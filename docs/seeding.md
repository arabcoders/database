# Seeding

Define a `SeederRunner`, place it in the configured seeder directory, and run it through `SeederService`. Seeders handle baseline data, fixtures, demo content, and repeatable setup tasks.

## Define a seeder

```php
<?php

declare(strict_types=1);

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'base_users', dependsOn: [], tags: ['base'], groups: ['dev'], mode: 'once')]
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

`SeederRunner` requires `__invoke(Connection $connection): void`. The `Seeder` attribute fields are `name`, `dependsOn`, `tags`, `groups`, and `mode`. Seeder names must be unique.

## Configure and run

`SeederService` accepts a PDO connection, seeder directory, and optional PSR container. `run()` accepts a `SeederRequest|string`, a dry-run default for the string form, and an optional callback:

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

`SeederRequest` defaults to an empty `classFilter`, `dryRun: true`, `mode: auto`, and `transactionMode: per-seeder`. Therefore the default request previews the selected run. Set `dryRun: false` for execution. `SeederResult` contains selected definitions, the dry-run flag, and execution entries with status, reason, and history ID.

`SeederService::list(): array` returns the discovered `SeederDefinition` values without running them.

## Filtering and dry runs

`classFilter` is a case-insensitive prefix filter on the seeder name. It must identify one name or the service throws for no match or multiple matches. `tag` and `group` filter the discovered roots. After filtering, required dependencies are added to the execution plan.

Set `dryRun: true` to return pending and skipped entries without creating the history table or executing seeders. This makes the returned plan suitable for review before data changes.

## Run modes and transactions

`SeederRunMode` defines `auto`, `once`, `always`, and `rebuild`:

- `auto` uses the mode declared by each seeder.
- `once` skips a seeder with a successful history row.
- `always` executes selected seeders regardless of prior successful runs.
- `rebuild` removes that seeder's previous history before running it again.

`SeederTransactionMode` defines `none`, `per-seeder`, and `per-run`. The default is `per-seeder`. `per-run` wraps the run in one transaction. `none` does not add transaction wrapping.

## History and dependencies

`SeederRegistry` scans configured directories, validates the attribute name, checks that classes extend `SeederRunner`, normalizes lists, and sorts definitions by name. `SeederDependencyResolver` adds `dependsOn` entries, orders the result, and detects dependency cycles.

`SeederExecutionHistory` stores execution rows in `seeder_version`, creates the table and index when execution begins, tracks `executed` and `failed` statuses, and supplies the successful-run check used by `once`. A failure during a per-run transaction is recorded after rollback. Database failures throw `arabcoders\database\DatabaseException`.
