# Seeding

Seeders are class-based, attribute-discovered units for populating data.

Core pieces:

- `arabcoders\database\Attributes\Seeder`
- `arabcoders\database\Seeder\SeederRunner`
- `arabcoders\database\Seeder\SeederRegistry`
- `arabcoders\database\Seeder\SeederExecutor`
- `arabcoders\database\Commands\SeederService`

## Defining a Seeder

```php
<?php

declare(strict_types=1);

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(
    name: 'base_users',
    dependsOn: [],
    tags: ['base'],
    groups: ['dev'],
)]
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

## Seeder Attribute Fields

- `name` - unique logical seeder id.
- `dependsOn` - seeder names that must run first.
- `tags` - optional labels.
- `groups` - optional grouping labels.
- `mode` - default run mode (`always`, `once`, `rebuild`).

## Registry and Dependency Resolution

`SeederRegistry` scans configured directories and validates:

- unique seeder names
- class extends `SeederRunner`

`SeederDependencyResolver` computes execution order and detects cycles.

## Running Seeders via SeederService

`SeederService` is the main programmatic entry point.

```php
<?php

declare(strict_types=1);

use arabcoders\database\Commands\SeederRequest;
use arabcoders\database\Commands\SeederService;
use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederTransactionMode;

$service = new SeederService($pdo, __DIR__ . '/seeders');

$result = $service->run(new SeederRequest(
    classFilter: '',
    dryRun: false,
    mode: SeederRunMode::AUTO,
    transactionMode: SeederTransactionMode::PER_SEEDER,
    tag: 'base',
    group: 'dev',
));
```

`SeederResult` returns:

- selected seeder definitions
- dry-run flag
- execution entries with status/reason/history id

## Run Modes

`SeederRunMode` values:

- `auto` - use each seeder's declared mode
- `once` - skip seeders already executed successfully
- `always` - always execute
- `rebuild` - remove previous history for that seeder before running

## Transaction Modes

`SeederTransactionMode` values:

- `none` - no transaction wrapping
- `per-seeder` - each seeder in its own transaction
- `per-run` - one transaction for the full run

## Execution History

`SeederExecutionHistory` stores status rows in `seeder_version`.

Behavior:

- creates table/index automatically
- tracks `executed` and `failed` runs
- used for `once` checks and `rebuild` semantics

## Filtering and Selection

`SeederService` supports:

- class/name prefix filtering (`classFilter`)
- tag filtering (`tag`)
- group filtering (`group`)

Dependency closure is applied to selected roots, then ordered execution is produced.

## Dry Run

When `dryRun` is `true`, the service returns planned entries without execution.

This is useful for previews in CI/CD pipelines or local verification tooling.
