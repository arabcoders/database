# ORM

Map PHP objects to tables and query them through repositories. Relations load only when requested.

## Create an OrmManager

Use `ConnectionManager` for named connections. `OrmManager::fromConnection()` wraps a single connection.

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Orm\OrmManager;

$pdo = new \PDO('sqlite:app.sqlite');
$connection = new Connection($pdo, DialectFactory::fromPdo($pdo));

$connections = new ConnectionManager();
$connections->register('default', $connection);

$orm = new OrmManager($connections);
```

`repository()` uses the default connection. Pass a connection name or use `repositoryOn()` to select another. `usingConnection()` returns a separate manager bound to that connection. The examples assume the mapped tables already exist.

## Map an entity

Use `#[Table]` on the class and `#[Column]` on public properties:

```php
<?php

declare(strict_types=1);

use arabcoders\database\Attributes\Orm\HasMany;
use arabcoders\database\Attributes\Orm\SoftDelete;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'users')]
#[SoftDelete(column: 'deleted_at')]
final class UserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::DateTime, nullable: true, name: 'deleted_at')]
    public ?string $deletedAt = null;

    #[HasMany(target: PostEntity::class, foreignKey: 'user_id', localKey: 'id')]
    public array $posts = [];
}
```

## Use repository CRUD

```php
$users = $orm->repository(UserEntity::class);

$one = $users->find(1);
$list = $users->findBy(['email' => 'a@example.com'], limit: 50);

$created = new UserEntity();
$created->email = 'b@example.com';
$id = $users->insert($created);
```

Reads use `find()` by primary key, `findBy()` with a criteria array, or `findWhere()` with a `Condition`. `findOneBy()` and `findOneWhere()` return one row; `count()`, `exists()`, `findPage()`, `cursor()`, and `chunked()` cover totals, existence, pagination, and streaming. `find()` requires a single-column primary key.

Writes use `insert()`, `save()`, `updateChanged()`, `upsert()`, `delete()`, and bulk or criteria-based variants such as `insertMany()`, `updateWhere()`, and `deleteBy()`. `updateChanged()` writes only the values you pass.

`insert()` returns the primary identifier. `save()` inserts when the primary key has no persisted value and updates otherwise. Bulk insert, update, upsert, and delete methods run in a transaction.

## Load relations

Relations use the `BelongsTo`, `HasOne`, `HasMany`, or `BelongsToMany` attributes. Eager loading accepts dotted paths:

```php
$items = $users->findBy([], relations: ['posts.comments', 'profile']);
```

Eager loading requires a full column selection; partial column requests throw.

Pass `RelationOptions` and call `where()`, `orderBy()`, or `limit()` to filter, order, or limit a relation. Use `limitPerParent()` for a per-parent limit:

```php
use arabcoders\database\Orm\RelationOptions;

$items = $users->findBy([], relations: [
    'posts' => (new RelationOptions())->orderBy('created_at', 'DESC')->limitPerParent(3),
]);
```

`limitPerParent()` limits rows per parent for `HasOne` and `HasMany` relations and requires window functions. It isn't available for `BelongsTo` or `BelongsToMany`, and it cannot be combined with `limit()` or an offset.

## Write relations

For `BelongsToMany` relations, use `attach()`, `detach()`, `sync()`, or `toggle()`. `attach()` accepts pivot values and an `$onDuplicate` policy: `DUPLICATE_BEHAVIOR_ERROR` (the default), `DUPLICATE_BEHAVIOR_IGNORE`, or `DUPLICATE_BEHAVIOR_UPDATE`.

For `HasOne` and `HasMany` relations, `saveRelated()` persists an existing related entity and `createRelated()` creates one from values.

Relation writes need a persisted parent with a non-null local key, a mapped foreign key on the related entity, and pivot table and key configuration for many-to-many relations.

## Soft deletes, lifecycle, and validation

With `#[SoftDelete]`, repository-built queries exclude rows where the soft-delete column is set. `withTrashed()` includes them and `onlyTrashed()` returns only deleted rows; both return repository clones. `delete()` still issues a physical delete.

The repository calls `beforeInsert`/`afterInsert`, `beforeUpdate`/`afterUpdate`, and `beforeDelete`/`afterDelete` when the entity defines them. If you pass a PSR-14 dispatcher to `OrmManager`, writes also dispatch `orm.entity.pre_insert`, `orm.entity.post_insert`, `orm.entity.pre_update`, `orm.entity.post_update`, `orm.entity.pre_delete`, and `orm.entity.post_delete`.

`Transform` callables encode values before persistence and decode them during hydration. `Validate` callables run for create, update, and hydrate by default; pass `ValidationType` values to restrict the phases. Call `withHydrateValidation()` to validate loaded rows.

## Object identity and refresh

`find()` reuses a previously loaded entity without querying the database. `insert()` retains the inserted entity; bulk inserts and streaming reads don't retain entities. Call `clearIdentityMap()` to release cached entities or `OrmManager::clear()` to clear the repositories.

Other full-entity queries refresh the same object and overwrite unsaved values by default. To keep unsaved fields while refreshing the rest, extend `BaseModel` and override `preserveDirtyOnHydrate()`:

```php
use arabcoders\database\Model\BaseModel;

final class UserEntity extends BaseModel
{
    public function preserveDirtyOnHydrate(): bool
    {
        return true;
    }
}
```

An entity that doesn't extend `BaseModel` can implement `arabcoders\database\Model\PreservesDirtyStateOnHydrate` directly.

## Partial updates

If an entity implements `arabcoders\database\Model\ProvidesDiff`, `save()` sends only the `diff()` values. Pass `force: true` to `save()` for a full update.

## BaseModel export

`BaseModel::toArray()` returns mapped fields and omits names listed in `protected array $_protected`. `toArray(omit: false)` includes them. `json_encode()` uses the same output. Protected fields still participate in change tracking. Add fields to `protected array $ignored` to exclude them from `diff()` and `apply()`.

## Streaming defaults

`cursorById()` fetches 1,000 rows per query by default; pass `batchSize:` to change it. `chunkedById()` uses `size:` as the query batch size. `chunked()` also defaults to 1,000 rows per chunk.
