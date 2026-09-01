# Schema and migrations

Choose the schema path before writing code:

- Use model SQL when the application needs SQL for a new schema or for a controlled, one-off operation. `SchemaGenerator` builds a `MigrationSql` value from model attributes.
- Use migrations when schema changes must be ordered, recorded, previewed, checked for drift, and rolled back where the migration defines a reversible plan.

## Model SQL

Models describe tables with these attributes:

- `arabcoders\database\Attributes\Schema\Table`
- `arabcoders\database\Attributes\Schema\Column`
- `arabcoders\database\Attributes\Schema\Index`
- `arabcoders\database\Attributes\Schema\Unique`
- `arabcoders\database\Attributes\Schema\ForeignKey`

Use `SchemaGenerator::generateSchemas()` for SQL for an empty schema:

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

The other public helpers are `generateSchema(string $modelClass, ...)`, `tableDefinition(string $modelClass)`, and `schemaDefinition(array $modelClasses)`. A model rename can use `Table(prevName: 'old_table')` or `Column(prevName: 'old_column')`; the differ can then emit a rename operation instead of a drop and recreate.

## Migration workflow

A migration is an attribute-discovered class extending `SchemaBlueprintMigration`. Its `#[Migration]` attribute supplies the ordered `id`, an optional `name`, and optional squash metadata. The migration receives a `Blueprint` and records schema operations. `SchemaBlueprintMigration` calls `change(Blueprint $blueprint)` by default, or the class can override `__invoke(Connection $runner, Blueprint $blueprint)`.

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

`Blueprint::createTable()`, `table()`, `dropTable()`, and `renameTable()` are the main table operations. Use the concrete `TableBlueprint` type in the closure when you want to declare it explicitly. A migration's `down` plan is derived from its recorded blueprint operations, so inspect the generated plan before relying on rollback for a change.

## Configure the registry and runner

`MigrationRegistry` scans directories for `#[Migration]` classes. It accepts an array of paths and an optional PSR container. `BlueprintMigrationRunner` accepts the PDO connection, registry, version table name, and lock table name:

```php
use arabcoders\database\Schema\Migration\BlueprintMigrationRunner;
use arabcoders\database\Schema\Migration\MigrationRegistry;

$registry = new MigrationRegistry([__DIR__ . '/migrations']);
$runner = new BlueprintMigrationRunner($pdo, $registry);
```

For application code that needs the complete workflow, configure `MigrationService` with the PDO connection, migration directory, optional version table, and optional PSR container:

```php
use arabcoders\database\Commands\MigrationService;

$service = new MigrationService($pdo, __DIR__ . '/migrations');
```

The registry rejects missing IDs, duplicate IDs, and classes that do not extend `SchemaBlueprintMigration`.

## Create and autogenerate

`MigrationCreator` requires the migration directory and a `MigrationTemplate`:

```php
use arabcoders\database\Commands\MigrationCreator;
use arabcoders\database\Schema\Migration\MigrationTemplate;

$template = new MigrationTemplate();
$creator = new MigrationCreator(__DIR__ . '/migrations', $template);
$draft = $creator->createBlank('create widgets');
$creator->persist($draft);
```

`MigrationTemplate` configures the namespace and class imports used by every file the creator generates. Applications that replace migration base classes or schema types can set those constructor fields once. Reuse the configured template when constructing other migration-file builders.

`createAutogen()` compares model attributes with the live PDO schema. It accepts the migration name, PDO, model paths, ignored tables, orphan-drop setting, dry-run flag, and optional ID generator. `createAutogenWithOptions()` also accepts `MigrationAutogenOptions`, which carries introspection options, orphan handling, dry-run output, and schema augmenters. A dry run returns `MigrationPreview`; otherwise the method returns `MigrationDraft` for `persist()`.

## Preview, run, and rollback

`MigrationService` exposes the application-facing operations:

- `list(): MigrationListResult` lists migrations, applied state, checksums, and lock information.
- `probe(MigrationRequest $request): MigrationProbeResult` inspects pending work and issues without changing migration metadata or taking a lock.
- `migrate(MigrationRequest $request): MigrationOperationResult` runs or previews migrations.
- `skipUpTo(string $token, bool $dryRun = false, bool $force = false, bool $repair = false): MigrationSkipResult` marks migrations applied without running their schema operations.
- `buildDryRunSql(string $direction, array $migrations): array` renders SQL for selected migration definitions.

`MigrationRequest` has `direction`, `dryRun`, `steps`, `force`, and `repair`. Its default `dryRun` is `true`. Set `dryRun: false` to apply changes. Use `direction: 'down'` to roll back. A down request defaults to one step when `steps` is zero.

```php
use arabcoders\database\Commands\MigrationRequest;

$preview = $service->migrate(new MigrationRequest(direction: 'up', dryRun: true));
$applied = $service->migrate(new MigrationRequest(direction: 'up', dryRun: false));
$rolledBack = $service->migrate(new MigrationRequest(direction: 'down', dryRun: false, steps: 1));
```

The runner ensures `migration_version` and `migration_lock` exist before migration operations. It acquires the lock when applying changes, checks migration ordering, records checksums, and validates checksum drift. `migration_version` is not altered to add a missing checksum column. Use `repair` only through an intentional operational procedure, and review the preview and database backup policy before applying changes.

## Advanced schema behavior

SQLite has limited `ALTER TABLE` support. When a change cannot be expressed safely with native alter operations, `SchemaSqlRenderer` uses `RebuildTableOperation`. The rebuild renames the old table, creates the new table, copies shared columns, drops the old table, and recreates indexes from the target definition.

Autogen schema augmenters run after introspection and before normalization and diffing. They receive the model target schema, live database schema, schema dialect, and PDO connection. Use `AutogenSchemaAugmenterInterface` to preserve externally managed indexes. On SQLite, an ignored index exists only outside the source schema. Inject it into the target schema if a rebuild must recreate it.

## Squashing and deployment safety

`MigrationSquasher` requires both the migration directory and a `MigrationTemplate`:

```php
use arabcoders\database\Commands\MigrationSquasher;

$squasher = new MigrationSquasher(__DIR__ . '/migrations', $template);
$report = $squasher->squash('260101120000', apply: false);
```

The report contains `start`, `end`, `latestFile`, `newContents`, and `deletedFiles`. With `apply: true`, the latest migration is overwritten and earlier files in the selected range are removed. The generated latest migration retains its ID and records `squashedFrom` plus the retained file's pre-squash `squashedChecksum`.

Squash replay currently uses the SQLite query dialect. Review migrations that branch on the connection dialect before applying the generated file.

A fresh database runs the squashed migration. An existing database at or beyond its ID recognizes the replaced history from the squash metadata and updates the stored checksum. A database whose current migration is inside the replaced range is rejected. Deploy the original migrations through the retained ID before deploying the squash. The squashed migration is a rollback boundary: newer migrations can roll back, but rollback cannot remove the squashed migration or enter the replaced history.

Review generated SQL, run a dry-run or probe, apply migrations under the runner's lock, and keep the migration files and deployment order consistent across instances. The runner records a checksum for each applied migration and validates it on later runs. A changed file raises `MigrationChecksumMismatchException`; `repair` is an explicit runner option for repairing checksum state.

## Reference: migration exceptions

Catch `MigrationOrderException`, `MigrationLockException`, `MigrationMissingException`, `MigrationChecksumMismatchException`, and `MigrationStateException` in deployment tooling. Schema introspection and migration metadata PDO failures throw `arabcoders\database\DatabaseException`.
