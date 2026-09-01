# ORM

The ORM maps attribute-defined entities to explicit repository operations. Entities stay as regular PHP objects, and repositories handle hydration, persistence, and relation loading. The package doesn't rely on runtime schema mutation or lazy-loading proxies.

## 1. Create an OrmManager

Use `ConnectionManager` when the application works with named connections. For one connection, use `OrmManager::fromConnection()`.

```php
<?php

declare(strict_types=1);

use PDO;
use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Orm\OrmManager;

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo, DialectFactory::fromPdo($pdo));

$connections = new ConnectionManager();
$connections->register('default', $connection);

$orm = new OrmManager($connections);

// With one connection:
// $orm = OrmManager::fromConnection($connection);
```

`repository()` uses the manager's default connection. Pass a connection name to `repository()` or use `repositoryOn()` when an entity belongs to another named connection. `usingConnection()` returns a manager clone that uses the selected connection.

## 2. Map an entity

Every entity needs:

- A class-level `#[arabcoders\database\Attributes\Schema\Table(...)]` attribute.
- Public properties with `#[arabcoders\database\Attributes\Schema\Column(...)]`.

An entity can also use relation attributes from `arabcoders\database\Attributes\Orm\*`, `#[arabcoders\database\Transformer\Transform(...)]`, `#[arabcoders\database\Validator\Validate(...)]`, and a class-level `#[arabcoders\database\Attributes\Orm\SoftDelete(...)]` attribute.

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

## 3. Use repository CRUD

Get a repository from `OrmManager` and call the operation you need.

```php
$users = $orm->repository(UserEntity::class);

$one = $users->find(1);
$list = $users->findBy(['email' => 'a@example.com'], limit: 50);

$created = new UserEntity();
$created->email = 'b@example.com';
$id = $users->insert($created);
```

Common read methods include:

- `find`, `findBy`, `findWhere`, `findOneBy`, and `findOneWhere`.
- `count`, `countWhere`, `exists`, and `existsWhere`.
- `findPage`, `findPageWhere`, `cursor`, `chunked`, `cursorById`, and `chunkedById`.

Common write methods include:

- `insert`, `insertMany`, `save`, and `updateChanged`.
- `updateWhere`, `updateBy`, and `updateMany`.
- `upsert` and `upsertMany`.
- `delete`, `deleteWhere`, `deleteBy`, and `deleteMany`.

`save()` inserts entities without a persisted primary key and updates existing entities. `insert()` returns the primary identifier. Bulk insert, update, upsert, and delete methods run their work in a transaction.

## 4. Load relations

The ORM supports these relation attributes:

- `BelongsTo`.
- `HasOne`.
- `HasMany`.
- `BelongsToMany`.

Eager loading accepts dotted paths:

```php
$items = $users->findBy([], relations: ['posts.comments', 'profile']);
```

Relation entries can use `RelationOptions` to add `where(...)`, `orderBy(...)`, `orderByRaw(...)`, or `limit(...)`. `limitPerParent(...)` limits has-relation rows for each parent when the database supports window functions. It isn't available for `BelongsTo` or `BelongsToMany`.

## 5. Write relations

For `BelongsToMany` relations, `EntityRepository` provides:

- `attach($entity, $relationName, $related, $pivot = [], $onDuplicate = ...)`
- `detach($entity, $relationName, $related = null)`
- `sync($entity, $relationName, $related)`
- `toggle($entity, $relationName, $related, $pivot = [])`

`attach()` supports these duplicate-handling constants:

- `DUPLICATE_BEHAVIOR_ERROR`
- `DUPLICATE_BEHAVIOR_IGNORE`
- `DUPLICATE_BEHAVIOR_UPDATE`

For `HasOne` and `HasMany` writes, use `saveRelated(...)` for an existing related entity and `createRelated(...)` to create and persist one.

Relation writes require a persisted parent with a non-null local key. The related foreign key must be mapped on the related entity. Many-to-many writes also require pivot table and pivot key configuration from `BelongsToMany`.

## 6. Soft deletes, lifecycle, and transforms

When an entity uses `#[SoftDelete]`, normal queries exclude deleted rows. Use `withTrashed()` to include them or `onlyTrashed()` to return only deleted rows. These methods return repository clones.

If an entity defines any of these methods, the repository calls them during writes:

- `beforeInsert` and `afterInsert`.
- `beforeUpdate` and `afterUpdate`.
- `beforeDelete` and `afterDelete`.

The repository can also dispatch these `EntityEvent` names:

- `orm.entity.pre_insert`
- `orm.entity.post_insert`
- `orm.entity.pre_update`
- `orm.entity.post_update`
- `orm.entity.pre_delete`
- `orm.entity.post_delete`

Pass a PSR event dispatcher to `OrmManager` for event integration.

`Transform` callables encode values before persistence and decode them during hydration. `Validate` callables can run for specific operations such as `create`, `update`, or `hydrate`. Enable hydration-time validation with `withHydrateValidation()` when loaded data should be checked as it is mapped.

## Advanced identity-map behavior

Each repository instance maintains its own identity map. Full-entity reads and `insert()` track entities, so repeated reads through the same repository return the same object. `insertMany()`, `cursor()`, `chunked()`, `cursorById()`, and `chunkedById()` don't track their results.

Call `clearIdentityMap()` on a repository to release its tracked entities. `OrmManager` caches repository instances per entity class and connection scope. Call `OrmManager::clear()` to discard those repositories.

## Advanced dirty refresh

Full-entity lookups reuse tracked instances from the identity map. If an entity is loaded, changed in memory, and fetched again through the same repository, the repository hydrates the existing object instead of creating a new one.

When an entity extends `arabcoders\database\Model\BaseModel`, it can preserve unsaved mapped fields during that refresh:

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

- `preserveDirtyOnHydrate()` returns `false` by default, allowing hydration to overwrite dirty mapped fields.
- When it returns `true`, dirty mapped fields stay untouched, clean mapped fields refresh from the database, and only the refreshed fields are marked clean.
- An entity that doesn't extend `BaseModel` can implement `arabcoders\database\Model\PreservesDirtyStateOnHydrate` directly.

## Advanced partial updates

If an entity implements `arabcoders\database\Model\ProvidesDiff`, `save()` uses the `diff()` output as the update payload unless a full update is forced.

## BaseModel export behavior

Entities that extend `arabcoders\database\Model\BaseModel` can hide selected mapped fields from array and JSON output by defining `protected array $_protected = [...]`.

- `toArray()` omits protected fields by default.
- `toArray(omit: false)` includes them.
- `json_encode($entity)` uses the same export as `toArray()`, so models with `#[Column]` attributes serialize mapped column fields rather than every public property.
- Protected fields still participate in change tracking. Use `$ignored` when a field should also be skipped by `diff()` and `apply()`.

## Streaming batch sizes

`cursorById()` fetches up to 1,000 rows per query by default. Pass `batchSize` to change that query size. `chunkedById()` uses its `size` as the query batch size. `chunked()` also defaults to a size of 1,000.

## Reference constraints

- Entities must use public mapped properties.
- `find($id)` requires a single-column primary key.
- Eager loading isn't available when only partial select columns are requested.
- `limitPerParent(...)` isn't supported for `BelongsTo` and `BelongsToMany`.
- `limitPerParent(...)` can't be combined with `limit(...)` or an offset in the same `RelationOptions` instance.
