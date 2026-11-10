# Dialects

## Built-in drivers

Use `mysql`, `pgsql`, or `sqlite`. Select query and schema dialects from the PDO connection:

```php
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;

$dialect = DialectFactory::fromPdo($pdo);
$schemaDialect = SchemaDialectFactory::fromPdo($pdo);
```

For schema SQL without a connection, select by driver name:

```php
$schemaDialect = SchemaDialectFactory::fromDriverName('pgsql');
```

## Custom schema dialect

Implement `SchemaDialectInterface` or extend `AbstractSchemaDialect`, then register the class under a driver key:

```php
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;

SchemaDialectFactory::register('custom', CustomSchemaDialect::class);
$dialect = SchemaDialectFactory::fromDriverName('custom');
```

Custom schema dialect constructors must accept an optional query dialect to support both driver-name and PDO selection.

Schema registration doesn't register a query dialect. For custom query SQL, implement `DialectInterface` and pass the instance directly to `Connection`.

## Schema differences

- SQLite column and constraint changes may rebuild tables. See [preserving indexes](schema-migrations.md#sqlite-indexes).
- PostgreSQL and SQLite support expression and partial indexes. PostgreSQL also supports index methods such as `gin` and `gist`.
- MySQL supports full-text, spatial, and index algorithms, but rejects partial indexes.

See [query driver limits](query-builder.md#driver-limits) for locks, upserts, and `RETURNING`.
