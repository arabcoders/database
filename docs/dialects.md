# Dialects and Extensibility

The package uses two dialect layers:

- DML dialects (`arabcoders\database\Dialect\*`) for query builder SQL.
- DDL dialects (`arabcoders\database\Schema\Dialect\*`) for schema/migration SQL.

## Built-In Drivers

- `mysql`
- `pgsql`
- `sqlite`

## DML Dialects

Interface: `arabcoders\database\Dialect\DialectInterface`

Key responsibilities:

- identifier and string quoting
- limit/offset rendering
- feature flags (`supportsReturning`, `supportsUpsertDoNothing`, `supportsWindowFunctions`, `supportsFullText`)
- inserted-value expression for upsert updates (`renderUpsertInsertValue`)

Selection:

```php
$dialect = arabcoders\database\Dialect\DialectFactory::fromPdo($pdo);
```

Driver notes:

- MySQL `RETURNING` depends on server version and excludes MariaDB.
- PostgreSQL and SQLite support `RETURNING` and `DO NOTHING` upsert mode.

## DDL Dialects

Interface: `arabcoders\database\Schema\Dialect\SchemaDialectInterface`

Responsibilities:

- create/alter/drop table/column/index/foreign key SQL
- rename SQL
- capability flags (`supportsAlterColumn`, `supportsDropColumn`, etc.)
- type normalization and default algorithm decisions

Selection:

```php
$schemaDialect = arabcoders\database\Schema\Dialect\SchemaDialectFactory::fromPdo($pdo);
```

## Factory and Registration

`SchemaDialectFactory` supports:

- `fromPdo($pdo)`
- `fromDriverName('pgsql')`
- `fromTarget(...)` for schema dialect instance/class, database dialect instance/class, or driver string
- `register($driver, $schemaDialectClass)` for custom drivers

## Extending With a Custom DML Dialect

1. Implement `DialectInterface`.
2. Ensure dialect `name()` matches your driver key.
3. Add factory wiring in your application around `DialectFactory` (or your own factory wrapper).

## Extending With a Custom DDL Dialect

1. Implement `SchemaDialectInterface` (or extend `AbstractSchemaDialect`).
2. Register with `SchemaDialectFactory::register($driver, YourSchemaDialect::class)`.
3. Ensure your dialect handles renderer operations used by `SchemaSqlRenderer`.

## Query-Level Feature Gates

Query objects enforce feature availability at compile time, for example:

- unsupported lock clauses
- unsupported set operations
- unsupported returning/upsert forms
- unsupported joined updates/deletes

When adding a dialect, implement feature flags accurately to keep these checks correct.

## Schema-Level Driver Nuances

- SQLite uses rebuild-based strategies for unsupported alter/drop operations.
- PostgreSQL supports expression/partial indexes and specific index method rendering.
- MySQL handles fulltext/spatial/index algorithm variants with driver-specific syntax.

Keep these differences explicit in your custom dialect to avoid hidden behavior drift.
