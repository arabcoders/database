# Seeding

Seeders are classes discovered through attributes. They are useful for baseline data, local fixtures, demo content, and any other setup you want to run in a repeatable way.

## Key Classes

The seeding system is built around these types:

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

The seeder attribute supports these fields:

- `name` for the unique logical seeder identifier.
- `dependsOn` for seeder names that must run first.
- `tags` for optional labels.
- `groups` for optional grouping labels.
- `mode` for the default run mode (`always`, `once`, or `rebuild`).

## Registry and Dependency Resolution

`SeederRegistry` scans the configured directories, discovers seeder classes by attribute, and validates that each seeder name is unique and each class extends `SeederRunner`.

`SeederDependencyResolver` adds required dependencies, sorts seeders into a safe execution order, and detects cycles.

## Running Seeders With SeederService

`SeederService` is the main entry point when you want to run seeders from application code or a console command.

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

`SeederResult` includes:

- The selected seeder definitions.
- The dry-run flag.
- Execution entries with status, reason, and history id.

## Run Modes

`SeederRunMode` supports:

- `auto`, which uses each seeder's declared mode.
- `once`, which skips seeders that already ran successfully.
- `always`, which always executes the selected seeders.
- `rebuild`, which removes previous history for that seeder before running it again.

## Transaction Modes

`SeederTransactionMode` supports:

- `none`, which runs without transaction wrapping.
- `per-seeder`, which wraps each seeder in its own transaction.
- `per-run`, which wraps the full seeding run in one transaction.

## Execution History

`SeederExecutionHistory` stores status rows in `seeder_version`.

It:

- Creates the table and index automatically.
- Tracks both `executed` and `failed` runs.
- Powers `once` checks and `rebuild` behavior.

## Filtering and Selection

`SeederService` supports:

- Class or name prefix filtering through `classFilter`.
- Tag filtering through `tag`.
- Group filtering through `group`.

After filtering, the service adds any required dependencies and then builds the final execution order.

## Dry Run

When `dryRun` is `true`, the service returns the seeding plan without running anything.

This is useful when you want to preview a deployment, release, or local setup run before it changes data.
