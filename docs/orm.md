# ORM

The ORM layer is attribute-driven and explicit.

- Entities are plain PHP objects.
- Mapping comes from attributes.
- Repositories execute explicit query operations.

No runtime schema mutation or hidden lazy-loading proxies are used.

## Building an OrmManager

`OrmManager` is manager-centric and uses `ConnectionManager`.

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

// Single connection helper:
// $orm = OrmManager::fromConnection($connection);
```

## Entity Mapping

Required:

- class-level `#[arabcoders\database\Attributes\Schema\Table(...)]`
- public properties with `#[arabcoders\database\Attributes\Schema\Column(...)]`

Optional:

- relation attributes in `arabcoders\database\Attributes\Orm\*`
- `#[arabcoders\database\Transformer\Transform(...)]`
- `#[arabcoders\database\Validator\Validate(...)]`
- class-level `#[arabcoders\database\Attributes\Orm\SoftDelete(...)]`

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
    public int $id;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email;

    #[Column(type: ColumnType::DateTime, nullable: true, name: 'deleted_at')]
    public ?string $deletedAt = null;

    #[HasMany(target: PostEntity::class, foreignKey: 'user_id', localKey: 'id')]
    public array $posts = [];
}
```

## Repository Access

```php
$users = $orm->repository(UserEntity::class);

$one = $users->find(1);
$list = $users->findBy(['email' => 'a@example.com'], limit: 50);

$created = new UserEntity();
$created->email = 'b@example.com';
$id = $users->insert($created);
```

Key reads:

- `find`, `findBy`, `findWhere`, `findOneBy`, `findOneWhere`
- `count`, `countWhere`, `exists`, `existsWhere`
- `findPage`, `findPageWhere`
- `cursor`, `chunked`, `cursorById`, `chunkedById`

Key writes:

- `insert`, `insertMany`
- `save`, `updateChanged`, `updateWhere`, `updateBy`, `updateMany`
- `upsert`, `upsertMany`
- `delete`, `deleteWhere`, `deleteBy`, `deleteMany`

## Identity Map and Repository Cache

- Identity map is per repository instance.
- `OrmManager` caches repository instances per entity class and connection scope.
- Call `OrmManager::clear()` to reset cached repositories.

## Soft Delete

With `#[SoftDelete]` on the entity class:

- default queries include only non-deleted rows
- `withTrashed()` includes deleted rows
- `onlyTrashed()` limits to deleted rows

## Relations and Eager Loading

Supported relation attributes:

- `BelongsTo`
- `HasOne`
- `HasMany`
- `BelongsToMany`

Eager loading accepts dotted paths:

```php
$items = $users->findBy([], relations: ['posts.comments', 'profile']);
```

Relation options use `RelationOptions`:

- `where(...)`
- `orderBy(...)`, `orderByRaw(...)`
- `limit(...)`
- `limitPerParent(...)` (has relations only; requires window functions)

## Many-to-Many Write Helpers

`EntityRepository` includes write helpers for `BelongsToMany`:

- `attach($entity, $relationName, $related, $pivot = [], $onDuplicate = ...)`
- `detach($entity, $relationName, $related = null)`
- `sync($entity, $relationName, $related)`
- `toggle($entity, $relationName, $related, $pivot = [])`

`attach()` duplicate policies:

- `DUPLICATE_BEHAVIOR_ERROR`
- `DUPLICATE_BEHAVIOR_IGNORE`
- `DUPLICATE_BEHAVIOR_UPDATE`

For has-one/has-many writes:

- `saveRelated(...)`
- `createRelated(...)`

## Lifecycle Hooks and Events

Entity methods (if present):

- `beforeInsert`, `afterInsert`
- `beforeUpdate`, `afterUpdate`
- `beforeDelete`, `afterDelete`

Dispatched events (`EntityEvent`):

- `orm.entity.pre_insert`
- `orm.entity.post_insert`
- `orm.entity.pre_update`
- `orm.entity.post_update`
- `orm.entity.pre_delete`
- `orm.entity.post_delete`

Pass a PSR event dispatcher to `OrmManager` if you need event integration.

## Transforms and Validation

- `Transform` callables encode before persistence and decode during hydration.
- `Validate` callables can run by operation type (`create`, `update`, `hydrate`, etc.).
- Enable hydration-time validation with `withHydrateValidation()`.

## Partial Updates With ProvidesDiff

If an entity implements `arabcoders\database\Model\ProvidesDiff`, `save()` uses `diff()` output for update payloads (unless forced full update).

## BaseModel Export Helpers

Entities extending `arabcoders\database\Model\BaseModel` can hide selected mapped fields from array and JSON export by defining `protected array $_protected = [...]`.

- `toArray()` omits protected fields by default.
- `toArray(omit: false)` includes protected fields.
- `json_encode($entity)` uses the same export as `toArray()`, so models with `#[Column]` attributes serialize mapped column fields rather than every public property.
- Protected fields still participate in change tracking; use `$ignored` when a field should also be skipped by `diff()` and `apply()`.

## Common Constraints

- Entities must use public mapped properties.
- `find($id)` requires a single-column primary key.
- relation eager loading with partial select columns is rejected.
- per-parent relation limit is not supported for belongs-to and many-to-many.
