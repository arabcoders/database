# Schema and migrations

## Generate SQL from models

Describe tables with `Table`, `Column`, `Index`, `Unique`, and `ForeignKey` attributes from `arabcoders\database\Attributes\Schema`. See [entity mapping](orm.md#map-an-entity) for an example.

Generate SQL for an empty database:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\SchemaGenerator;
use Example\Model\Todo;
use Example\Model\User;

$dialect = SchemaDialectFactory::fromDriverName('pgsql');
$sql = SchemaGenerator::generateSchemas([User::class, Todo::class], $dialect);

$upStatements = $sql->up;
$downStatements = $sql->down;
```

For renames, set `Table(prevName: 'old_table')` or `Column(prevName: 'old_column')` rather than dropping and recreating the table or column.

## Write a migration

Extend `SchemaBlueprintMigration`, implement `change()`, and assign a unique, ordered migration ID:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Attributes\Migration;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Blueprint\TableBlueprint;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

#[Migration(id: '260101120000', name: 'create_widgets')]
final class Migration_260101120000 extends SchemaBlueprintMigration
{
    public function change(Blueprint $blueprint): void
    {
        $blueprint->createTable('widgets', static function (TableBlueprint $table): void {
            $table->column('id', ColumnType::Int)->primary()->autoIncrement();
            $table->column('name', ColumnType::VarChar, length: 255);
        });
    }
}
```

Use `createTable()`, `table()`, `dropTable()`, and `renameTable()` on the blueprint. Rollback SQL is generated automatically; review it before relying on a change being reversible.

## Create and autogenerate

```php
use arabcoders\database\Commands\MigrationCreator;
use arabcoders\database\Schema\Migration\MigrationTemplate;

$template = new MigrationTemplate();
$creator = new MigrationCreator(__DIR__ . '/migrations', $template);
$draft = $creator->createBlank('create widgets');
$creator->persist($draft);
```

Set the generated namespace and imports through `MigrationTemplate`.

To generate a migration from model changes:

```php
$draft = $creator->createAutogen('update widgets', $pdo, [__DIR__ . '/models']);
$creator->persist($draft);
```

Pass `dryRun: true` to return SQL in a `MigrationPreview` instead of a file draft. Tables without models are left alone unless `dropOrphans: true` is set.

## Preview, run, and rollback

```php
use arabcoders\database\Commands\MigrationRequest;
use arabcoders\database\Commands\MigrationService;

$service = new MigrationService($pdo, __DIR__ . '/migrations');
$preview = $service->migrate(new MigrationRequest(direction: 'up', dryRun: true));
$sql = $service->buildDryRunSql('up', $preview->migrations);
$applied = $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));
$rolledBack = $service->migrate(new MigrationRequest(direction: 'down', dryRun: false, steps: 1));
```

Requests default to `dryRun: true`. Set `dryRun: false` to apply changes. Rollback defaults to one migration; use `steps` to change that.

Use `list()` to inspect applied migrations or `probe($request)` to check pending work without changing the database. `skipUpTo($id)` marks migrations as applied without running them.

Back up the database before applying schema changes. Don't edit applied migration files: checksum mismatches stop execution. Use `repair: true` only after reviewing the changed file against the deployed schema.

## SQLite indexes

SQLite may rebuild tables for column or constraint changes. To preserve indexes not declared on models, add them to the target schema using `AutogenSchemaAugmenterInterface`. Pass augmenters through `MigrationAutogenOptions` to `createAutogenWithOptions()`.

## Squashing and deployment safety

```php
use arabcoders\database\Commands\MigrationSquasher;

$squasher = new MigrationSquasher(__DIR__ . '/migrations', $template);
$report = $squasher->squash('260101120000', apply: false);
```

`apply: false` previews the replacement without changing files. `apply: true` replaces the latest migration and removes earlier files in the selected range. The latest migration keeps its ID.

Squashing uses the SQLite query dialect. Review migrations that branch on the connection dialect before applying the generated file.

Apply the original migrations through the retained ID on every existing database before deploying the squash. Databases partway through the replaced range are rejected. Fresh databases run the squashed migration. Rollback cannot remove the squashed migration or enter the replaced history.
