# Dialects

This is an advanced extension reference. Most applications only need built-in driver selection. The package has separate dialect layers for query SQL and schema or migration SQL.

## Select a built-in driver

`DialectFactory::fromPdo(PDO $pdo): DialectInterface` selects the query dialect from the PDO driver. The built-in drivers are `mysql`, `pgsql`, and `sqlite`:

```php
use arabcoders\database\Dialect\DialectFactory;

$dialect = DialectFactory::fromPdo($pdo);
```

`SchemaDialectFactory::fromPdo(PDO $pdo): SchemaDialectInterface` selects the schema dialect for the same connection:

```php
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;

$schemaDialect = SchemaDialectFactory::fromPdo($pdo);
```

Schema code can also select a built-in dialect by driver name:

```php
$schemaDialect = SchemaDialectFactory::fromDriverName('pgsql');
```

`SchemaDialectFactory::fromTarget()` accepts a schema dialect instance, a database dialect instance, a supported class string, or a driver string. Unsupported drivers throw `RuntimeException`.

## DML dialect internals

`arabcoders\database\Dialect\DialectInterface` defines the query-builder contract:

```php
interface DialectInterface
{
    public function name(): string;
    public function quoteIdentifier(string $identifier): string;
    public function quoteString(string $value): string;
    public function renderLimit(?int $limit, ?int $offset = null): string;
    public function supportsReturning(): bool;
    public function supportsUpsertDoNothing(): bool;
    public function supportsWindowFunctions(): bool;
    public function supportsFullText(): bool;
    public function renderUpsertInsertValue(string $column): string;
}
```

The feature methods gate SQL for `RETURNING`, upsert `DO NOTHING`, window functions, and full text. Query objects also reject unsupported locks, set operations, joined updates and deletes, and unsupported upsert forms. Keep each capability aligned with the SQL emitted by a custom dialect.

The built-in behavior is driver-specific. MySQL `RETURNING` depends on the server version and doesn't apply to MariaDB. PostgreSQL and SQLite support `RETURNING` and upsert `DO NOTHING`.

## DDL dialect internals

`arabcoders\database\Schema\Dialect\SchemaDialectInterface` supplies schema and migration SQL. Its methods cover table, column, index, foreign-key, rename, and primary-key operations, plus defaults and capabilities:

```php
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;

interface SchemaDialectInterface
{
    public function name(): string;
    public function quoteIdentifier(string $identifier): string;
    public function createTableSql(TableDefinition $table): string;
    public function dropTableSql(string $table): string;
    public function addColumnSql(string $table, ColumnDefinition $column): string;
    public function alterColumnSql(string $table, ColumnDefinition $column): string;
    public function dropColumnSql(string $table, string $column): string;
    public function addIndexSql(string $table, IndexDefinition $index): string|array;
    public function dropIndexSql(string $table, IndexDefinition $index): string|array;
    public function addForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string;
    public function dropForeignKeySql(string $table, ForeignKeyDefinition $foreignKey): string;
    public function renameTableSql(string $from, string $to): string;
    public function renameColumnSql(string $table, string $from, string $to): string;
    public function addPrimaryKeySql(string $table, array $columns): string;
    public function dropPrimaryKeySql(string $table): string;
    public function defaultTableEngine(): ?string;
    public function defaultTableCharset(): ?string;
    public function defaultTableCollation(): ?string;
    public function defaultIndexAlgorithm(IndexDefinition $index): ?string;
    public function normalizeColumnType(ColumnType $type): ColumnType;
    public function supportsAlterColumn(): bool;
    public function supportsDropColumn(): bool;
    public function supportsForeignKeys(): bool;
    public function supportsPrimaryKeyAlter(): bool;
}
```

The `TableDefinition`, `ColumnDefinition`, `IndexDefinition`, `ForeignKeyDefinition`, and `ColumnType` names in this signature are the classes imported by the production interface. `AbstractSchemaDialect` provides the shared constructor `__construct(DatabaseDialectInterface $dialect)`, identifier and literal quoting, default handling, and type normalization.

## Register a custom schema dialect

Implement `SchemaDialectInterface` or extend `AbstractSchemaDialect`, then register the class under a driver key:

```php
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;

SchemaDialectFactory::register('custom', CustomSchemaDialect::class);
$dialect = SchemaDialectFactory::fromDriverName('custom');
```

The custom class must implement `SchemaDialectInterface`. Its constructor must be compatible with the factory path used by the application. `SchemaDialectFactory::register()` changes the process-local registry; it doesn't add query-dialect support to `DialectFactory`. For a custom DML dialect, implement `DialectInterface` and provide application factory wiring because `DialectFactory` has no registration method.

## Built-in schema differences

SQLite uses rebuild operations when an alteration cannot be expressed by native `ALTER TABLE`. PostgreSQL supports expression indexes, partial indexes, and dialect-specific index methods. MySQL handles full-text, spatial, and index algorithm variants with driver-specific SQL. Custom capability flags must match the operations and SQL the dialect supports.
