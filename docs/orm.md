# ORM

The ORM maps attribute-defined entities to explicit repository operations. Entities stay as regular PHP objects, and repositories handle hydration, persistence, and relation loading. The package does not rely on runtime schema mutation or lazy-loading proxies.

## Creating an OrmManager

Use `ConnectionManager` when your application works with named connections. If you only need one connection, `OrmManager::fromConnection()` is a convenient shortcut.

```php
<?php

declare(strict_types=1);

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Orm\OrmManager;

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo, DialectFactory::fromPdo($pdo));

$connections = new ConnectionManager();
$connections->register('default', $connection);

$orm = new OrmManager($connections);

// If you only have one connection:
// $orm = OrmManager::fromConnection($connection);
```

## Entity Mapping

Every entity needs:

- A class-level `#[arabcoders\database\Attributes\Schema\Table(...)]` attribute.
- Public properties with `#[arabcoders\database\Attributes\Schema\Column(...)]`.

You can also add:

- Relation attributes from `arabcoders\database\Attributes\Orm\*`.
- `#[arabcoders\database\Transformer\Transform(...)]`.
- `#[arabcoders\database\Validator\Validate(...)]`.
- A class-level `#[arabcoders\database\Attributes\Orm\SoftDelete(...)]` attribute.

Example:

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

## Working With Repositories

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

## Identity Map and Repository Cache

Each repository instance maintains its own identity map. `OrmManager` also caches repository instances per entity class and connection scope, so repeated `repository()` calls usually return the same repository object. Call `OrmManager::clear()` when you want to discard cached repositories and start with fresh tracked state.

## Soft Delete

When an entity uses `#[SoftDelete]`, normal queries exclude deleted rows. Use `withTrashed()` to include them or `onlyTrashed()` to return only deleted rows.

## Relations and Eager Loading

The ORM supports these relation attributes:

- `BelongsTo`.
- `HasOne`.
- `HasMany`.
- `BelongsToMany`.

Eager loading accepts dotted paths:

```php
$items = $users->findBy([], relations: ['posts.comments', 'profile']);
```

You can customize relation queries with `RelationOptions`:

- `where(...)`
- `orderBy(...)` and `orderByRaw(...)`
- `limit(...)`
- `limitPerParent(...)` for has relations when the database supports window functions.

## Many-to-Many Write Helpers

`EntityRepository` provides helper methods for `BelongsToMany` relations:

- `attach($entity, $relationName, $related, $pivot = [], $onDuplicate = ...)`
- `detach($entity, $relationName, $related = null)`
- `sync($entity, $relationName, $related)`
- `toggle($entity, $relationName, $related, $pivot = [])`

`attach()` supports these duplicate-handling constants:

- `DUPLICATE_BEHAVIOR_ERROR`
- `DUPLICATE_BEHAVIOR_IGNORE`
- `DUPLICATE_BEHAVIOR_UPDATE`

For `HasOne` and `HasMany` write flows, use `saveRelated(...)` and `createRelated(...)`.

## Lifecycle Hooks and Events

If your entity defines any of the following methods, the repository will call them during writes:

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

Pass a PSR event dispatcher to `OrmManager` when you want event integration.

## Transforms and Validation

`Transform` callables encode values before persistence and decode them during hydration. `Validate` callables can run for specific operations such as `create`, `update`, or `hydrate`. Enable hydration-time validation with `withHydrateValidation()` when you want loaded data checked as it is mapped.

## Partial Updates With ProvidesDiff

If an entity implements `arabcoders\database\Model\ProvidesDiff`, `save()` uses the `diff()` output as the update payload unless you force a full update.

## Dirty-Aware Identity Map Refresh

Full-entity lookups reuse tracked instances from the identity map. If you load an entity, change it in memory, and then fetch the same row again through the same repository, the repository will hydrate the existing object instead of creating a new one.

When your entity extends `arabcoders\database\Model\BaseModel`, you can opt into preserving unsaved mapped fields during that refresh:

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

- `preserveDirtyOnHydrate()` returns `false` by default, so existing models keep their current behavior.
- When you return `true`, dirty mapped fields stay untouched, clean mapped fields refresh from the database, and only the refreshed fields are marked clean.
- If you do not extend `BaseModel`, you can implement `arabcoders\database\Model\PreservesDirtyStateOnHydrate` directly.

## BaseModel Export Helpers

Entities that extend `arabcoders\database\Model\BaseModel` can hide selected mapped fields from array and JSON output by defining `protected array $_protected = [...]`.

- `toArray()` omits protected fields by default.
- `toArray(omit: false)` includes them.
- `json_encode($entity)` uses the same export as `toArray()`, so models with `#[Column]` attributes serialize mapped column fields rather than every public property.
- Protected fields still participate in change tracking. Use `$ignored` when a field should also be skipped by `diff()` and `apply()`.

## Common Constraints

- Entities must use public mapped properties.
- `find($id)` requires a single-column primary key.
- Eager loading is not available when you request only partial select columns.
- `limitPerParent(...)` is not supported for `BelongsTo` and `BelongsToMany`.
