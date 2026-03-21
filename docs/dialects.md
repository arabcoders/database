# Dialects and Extensibility

The package uses two dialect layers so query generation and schema generation can stay explicit across different databases.

- DML dialects under `arabcoders\database\Dialect\*` generate SQL for the query builder.
- DDL dialects under `arabcoders\database\Schema\Dialect\*` generate SQL for schema and migration tooling.

## Built-In Drivers

Built-in driver support covers:

- `mysql`
- `pgsql`
- `sqlite`

## DML Dialects

The DML dialect interface is `arabcoders\database\Dialect\DialectInterface`.

Its responsibilities include:

- Identifier and string quoting.
- Limit and offset rendering.
- Feature flags such as `supportsReturning`, `supportsUpsertDoNothing`, `supportsWindowFunctions`, and `supportsFullText`.
- Inserted-value expressions for upsert updates through `renderUpsertInsertValue`.

Select the dialect for a PDO connection with:

```php
$dialect = arabcoders\database\Dialect\DialectFactory::fromPdo($pdo);
```

A few driver differences matter in practice:

- MySQL `RETURNING` support depends on server version and does not apply to MariaDB.
- PostgreSQL and SQLite both support `RETURNING` and `DO NOTHING` upsert mode.

## DDL Dialects

The DDL dialect interface is `arabcoders\database\Schema\Dialect\SchemaDialectInterface`.

It is responsible for:

- Create, alter, and drop SQL for tables, columns, indexes, and foreign keys.
- Rename statements.
- Capability flags such as `supportsAlterColumn` and `supportsDropColumn`.
- Type normalization and default algorithm decisions.

Select the schema dialect for a PDO connection with:

```php
$schemaDialect = arabcoders\database\Schema\Dialect\SchemaDialectFactory::fromPdo($pdo);
```

## Factory and Registration

`SchemaDialectFactory` provides:

- `fromPdo($pdo)`
- `fromDriverName('pgsql')`
- `fromTarget(...)` for a schema dialect instance or class, a database dialect instance or class, or a driver string
- `register($driver, $schemaDialectClass)` for custom drivers

## Extending With a Custom DML Dialect

To add a custom DML dialect:

1. Implement `DialectInterface`.
2. Make sure `name()` matches your driver key.
3. Add factory wiring around `DialectFactory`, or use your own factory wrapper.

## Extending With a Custom DDL Dialect

To add a custom DDL dialect:

1. Implement `SchemaDialectInterface`, or extend `AbstractSchemaDialect`.
2. Register it with `SchemaDialectFactory::register($driver, YourSchemaDialect::class)`.
3. Ensure it supports the renderer operations used by `SchemaSqlRenderer`.

## Query-Level Feature Gates

Query objects enforce feature availability at compile time. For example, the package rejects:

- Unsupported lock clauses.
- Unsupported set operations.
- Unsupported `RETURNING` or upsert forms.
- Unsupported joined updates and deletes.

When you add a custom dialect, keep these feature flags accurate so unsupported SQL is rejected early.

## Schema-Level Driver Nuances

Each built-in schema dialect keeps driver-specific behavior explicit:

- SQLite uses rebuild strategies for alter and drop operations it cannot express directly.
- PostgreSQL supports expression indexes, partial indexes, and dialect-specific index methods.
- MySQL handles full-text, spatial, and index algorithm variants with driver-specific syntax.

Keep those differences explicit in any custom dialect as well, so behavior stays predictable.
