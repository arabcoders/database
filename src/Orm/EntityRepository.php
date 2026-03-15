<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectInterface;
use arabcoders\database\Model\ProvidesDiff;
use arabcoders\database\Model\TracksChanges;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\DeleteQuery;
use arabcoders\database\Query\Identifier;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Query\RawExpression;
use arabcoders\database\Query\SelectQuery;
use arabcoders\database\Query\UpdateQuery;
use arabcoders\database\Query\UpsertValue;
use arabcoders\database\Transformer\TransformType;
use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use RuntimeException;

/**
 * @template TEntity of object
 */
final class EntityRepository
{
    public const string DUPLICATE_BEHAVIOR_ERROR = 'error';
    public const string DUPLICATE_BEHAVIOR_IGNORE = 'ignore';
    public const string DUPLICATE_BEHAVIOR_UPDATE = 'update';

    private EntityMetadata $metadata;
    private EntityHydrator $hydrator;
    private bool $includeTrashed = false;
    private bool $onlyTrashed = false;
    private bool $validateOnHydrate = false;
    /**
     * @var array<string,callable(self,SelectQuery):void>
     */
    private array $localScopes = [];
    /**
     * @var array<string,EntityRepository>
     */
    private array $repositoryCache = [];

    /**
     * @var array<string,object>
     */
    private array $identityMap = [];

    public function __construct(
        private Connection $connection,
        private EntityMetadataFactory $metadataFactory,
        private string $className,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->metadata = $this->metadataFactory->fromClass($className);
        $this->hydrator = new EntityHydrator();
    }

    /**
     * Get the connection used by this repository.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Find a single entity by primary key and optionally eager-load relations.
     *
     * @param array<int,string>|null $columns
     * @return TEntity|null
     * @throws RuntimeException If the entity uses a composite primary key.
     */
    public function find(mixed $id, array $relations = [], ?array $columns = null): ?object
    {
        if (1 !== count($this->metadata->primaryKeys)) {
            throw new RuntimeException('Find requires a single primary key.');
        }

        $selection = $this->resolveSelectColumns($columns);
        $this->assertRelationsSelectable($relations, $selection['full']);

        if ($selection['full']) {
            $identityKey = $this->identityKeyFromId($id);
            if (null !== $identityKey && isset($this->identityMap[$identityKey])) {
                return $this->identityMap[$identityKey];
            }
        }

        $primaryKey = $this->metadata->primaryKeys[0];
        $query = new SelectQuery($this->metadata->table)
            ->select($selection['columns'])
            ->where(Condition::equals($primaryKey, $id))
            ->limit(1);
        $this->applyScopes($query);

        $row = $this->connection->fetchOne($query);
        if (null === $row) {
            return null;
        }

        $entity = $this->hydrateRow($row, $selection['full']);
        $this->loadRelationsFor([$entity], $relations);

        return $entity;
    }

    /**
     * Execute select for this entity repository.
     * @return SelectQuery
     */

    public function select(): SelectQuery
    {
        $query = new SelectQuery($this->metadata->table)
            ->select(array_values($this->metadata->columnsByProperty));
        $this->applyScopes($query);

        return $query;
    }

    /**
     * Execute update for this entity repository.
     * @return UpdateQuery
     */
    public function update(): UpdateQuery
    {
        return new UpdateQuery($this->metadata->table);
    }

    /**
     * @param array<int,string> $columns
     */
    public function selectColumns(array $columns): SelectQuery
    {
        $query = new SelectQuery($this->metadata->table);
        $query->select($this->mapSelectableColumns($columns));
        $this->applyScopes($query);

        return $query;
    }

    /**
     * @param array<int,string> $columns
     */
    public function selectDistinct(array $columns): SelectQuery
    {
        return $this->selectColumns($columns)->distinct();
    }

    /**
     * Select mapped columns with explicit aliases.
     *
     * @param array<string,string> $columns
     * @return SelectQuery
     * @throws RuntimeException If aliases are not provided as key-value pairs.
     */
    public function selectColumnsAs(array $columns): SelectQuery
    {
        $query = new SelectQuery($this->metadata->table);
        $query->select([]);

        foreach ($columns as $column => $alias) {
            if (is_int($column)) {
                throw new RuntimeException('Select aliases must be keyed by column name.');
            }
            $query->selectAs($this->mapSelectableColumn($column), $alias);
        }

        $this->applyScopes($query);

        return $query;
    }

    /**
     * @param array<int,string> $columns
     */
    public function groupByColumns(SelectQuery $query, array $columns): SelectQuery
    {
        $query->groupBy($this->mapSelectableColumns($columns));

        return $query;
    }

    public function groupByRaw(SelectQuery $query, string $expression): SelectQuery
    {
        $query->groupByRaw($expression);

        return $query;
    }

    /**
     * @param array<int|string,string> $orderBy
     */
    public function orderByColumns(SelectQuery $query, array $orderBy): SelectQuery
    {
        $this->applyOrderBy($query, $orderBy);

        return $query;
    }

    /**
     * Hydrate all rows from a query into entities and eager-load requested relations.
     *
     * @return array<int,TEntity>
     */
    public function fetchAll(SelectQuery $query, array $relations = []): array
    {
        $entities = $this->hydrateRows($this->connection->fetchAll($query));
        $this->loadRelationsFor($entities, $relations);

        return $entities;
    }

    /**
     * Stream entities from a query and optionally eager-load relations per row.
     *
     * @return \Generator<int,TEntity>
     */
    public function cursor(SelectQuery $query, array $relations = []): \Generator
    {
        foreach ($this->connection->cursor($query) as $row) {
            $entity = $this->hydrateRow($row);
            $this->loadRelationsFor([$entity], $relations);
            yield $entity;
        }
    }

    /**
     * Stream entities in fixed-size chunks.
     *
     * @return \Generator<int,array<int,TEntity>>
     * @throws RuntimeException If the chunk size is less than 1.
     */
    public function chunked(SelectQuery $query, int $size = 1000, array $relations = []): \Generator
    {
        if ($size < 1) {
            throw new RuntimeException('Chunk size must be at least 1.');
        }

        $chunk = [];
        foreach ($this->cursor($query, []) as $entity) {
            $chunk[] = $entity;
            if (count($chunk) < $size) {
                continue;
            }

            if (!empty($relations)) {
                $this->loadRelationsFor($chunk, $relations);
            }

            yield $chunk;
            $chunk = [];
        }

        if (!empty($chunk)) {
            if (!empty($relations)) {
                $this->loadRelationsFor($chunk, $relations);
            }
            yield $chunk;
        }
    }

    /**
     * Stream entities by an ordered cursor column, applying optional global limit.
     *
     * @return \Generator<int,TEntity>
     */
    public function cursorById(
        ?int $limit = null,
        array $relations = [],
        string $column = 'id',
        string $direction = 'ASC',
    ): \Generator {
        $direction = $this->normalizeDirection($direction);
        $columnName = $this->mapSelectableColumn($column);
        $lastId = null;
        $fetched = 0;

        while (true) {
            $query = $this->select();
            if (null !== $lastId) {
                $query->where(
                    'ASC' === $direction
                        ? Condition::greaterThan($columnName, $lastId)
                        : Condition::lessThan($columnName, $lastId),
                );
            }

            $query->orderBy($columnName, $direction);
            if (null !== $limit) {
                $remaining = max(0, $limit - $fetched);
                if (0 === $remaining) {
                    return;
                }
                $query->limit($remaining);
            }

            $batch = $this->fetchAll($query, []);
            if (empty($batch)) {
                return;
            }

            if (!empty($relations)) {
                $this->loadRelationsFor($batch, $relations);
            }

            foreach ($batch as $entity) {
                $fetched += 1;
                $lastId = $this->resolveCursorValue($entity, $column);
                yield $entity;

                if (null !== $limit && $fetched >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Stream entities from cursorById in fixed-size chunks.
     *
     * @return \Generator<int,array<int,TEntity>>
     * @throws RuntimeException If the chunk size is less than 1.
     */
    public function chunkedById(
        int $size = 1000,
        ?int $limit = null,
        array $relations = [],
        string $column = 'id',
        string $direction = 'ASC',
    ): \Generator {
        if ($size < 1) {
            throw new RuntimeException('Chunk size must be at least 1.');
        }

        $chunk = [];
        foreach ($this->cursorById($limit, $relations, $column, $direction) as $entity) {
            $chunk[] = $entity;
            if (count($chunk) < $size) {
                continue;
            }

            yield $chunk;
            $chunk = [];
        }

        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    /**
     * @return TEntity|null
     */
    public function fetchOne(SelectQuery $query, array $relations = []): ?object
    {
        $this->applyScopes($query);
        $row = $this->connection->fetchOne($query);
        if (null === $row) {
            return null;
        }

        $entity = $this->hydrateRow($row);
        $this->loadRelationsFor([$entity], $relations);

        return $entity;
    }

    /**
     * Find entities by criteria with optional paging, ordering, relation loading, and column selection.
     *
     * @param array<string,mixed> $criteria
     * @param array<int,string>|null $columns
     * @return array<int,TEntity>
     */
    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): array {
        $condition = null;
        if (!empty($criteria)) {
            $condition = $this->buildCriteriaCondition($criteria);
        }

        $selection = $this->resolveSelectColumns($columns);
        $this->assertRelationsSelectable($relations, $selection['full']);

        $query = new SelectQuery($this->metadata->table)
            ->select($selection['columns'])
            ->limit($limit, $offset);
        $this->applyScopes($query);

        if (null !== $condition) {
            $query->where($condition);
        }

        $this->applyOrderBy($query, $orderBy);

        $entities = $this->hydrateRows($this->connection->fetchAll($query), $selection['full']);
        $this->loadRelationsFor($entities, $relations);

        return $entities;
    }

    /**
     * @param array<int,string>|null $columns
     * @return TEntity|null
     */
    public function findOneBy(
        array $criteria = [],
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): ?object {
        $results = $this->findBy($criteria, 1, null, $relations, $orderBy, $columns);

        return $results[0] ?? null;
    }

    /**
     * Find entities by an explicit condition with optional paging and relation loading.
     *
     * @param array<int,string>|null $columns
     * @return array<int,TEntity>
     */
    public function findWhere(
        Condition $condition,
        ?int $limit = null,
        ?int $offset = null,
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): array {
        $selection = $this->resolveSelectColumns($columns);
        $this->assertRelationsSelectable($relations, $selection['full']);

        $query = new SelectQuery($this->metadata->table)
            ->select($selection['columns'])
            ->where($condition)
            ->limit($limit, $offset);
        $this->applyScopes($query);

        $this->applyOrderBy($query, $orderBy);

        $entities = $this->hydrateRows($this->connection->fetchAll($query), $selection['full']);
        $this->loadRelationsFor($entities, $relations);

        return $entities;
    }

    /**
     * @param array<int,string>|null $columns
     * @return TEntity|null
     */
    public function findOneWhere(
        Condition $condition,
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): ?object {
        $results = $this->findWhere($condition, 1, null, $relations, $orderBy, $columns);

        return $results[0] ?? null;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        $condition = null;
        if (!empty($criteria)) {
            $condition = $this->buildCriteriaCondition($criteria);
        }

        $query = new SelectQuery($this->metadata->table)
            ->selectCount('*', 'total');
        $this->applyScopes($query);

        if (null !== $condition) {
            $query->where($condition);
        }

        $row = $this->connection->fetchOne($query);
        if (null === $row) {
            return 0;
        }

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Execute count where for this entity repository.
     * @param Condition $condition Condition.
     * @return int
     */

    public function countWhere(Condition $condition): int
    {
        $query = new SelectQuery($this->metadata->table)
            ->selectCount('*', 'total')
            ->where($condition);
        $this->applyScopes($query);

        $row = $this->connection->fetchOne($query);
        if (null === $row) {
            return 0;
        }

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function exists(array $criteria = []): bool
    {
        return $this->count($criteria) > 0;
    }

    public function existsWhere(Condition $condition): bool
    {
        return $this->countWhere($condition) > 0;
    }

    /**
     * Return a page payload for criteria-based queries.
     *
     * @param array<int,string>|null $columns
     * @return array{items:array<int,TEntity>,total:int,page:int,perPage:int}
     */
    public function findPage(
        int $page,
        int $perPage,
        array $criteria = [],
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): array {
        [$page, $perPage, $offset] = $this->normalizePagination($page, $perPage);

        $items = $this->findBy($criteria, $perPage, $offset, $relations, $orderBy, $columns);
        $total = $this->count($criteria);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Return a page payload for condition-based queries.
     *
     * @param array<int,string>|null $columns
     * @return array{items:array<int,TEntity>,total:int,page:int,perPage:int}
     */
    public function findPageWhere(
        Condition $condition,
        int $page,
        int $perPage,
        array $relations = [],
        array $orderBy = [],
        ?array $columns = null,
    ): array {
        [$page, $perPage, $offset] = $this->normalizePagination($page, $perPage);

        $items = $this->findWhere($condition, $perPage, $offset, $relations, $orderBy, $columns);
        $total = $this->countWhere($condition);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Insert an entity, run lifecycle hooks/events, and return its primary identifier.
     *
     * @param TEntity $entity
     * @return string
     * @throws ValidationException If entity validation fails.
     */
    public function insert(object $entity): string
    {
        $this->applyValidators($entity, ValidationType::CREATE);
        $this->dispatchEvent($entity, EntityEvent::PRE_INSERT);
        $this->callHook($entity, 'beforeInsert');
        $this->applyHooks($entity, 'create');
        $values = $this->extractValues($entity, true);
        $query = new InsertQuery($this->metadata->table)->values($values);
        $this->connection->execute($query);

        $id = $this->connection->lastInsertId();
        $this->applyGeneratedId($entity, $id);
        $this->trackEntity($entity);
        $this->syncTrackedEntity($entity);
        $this->callHook($entity, 'afterInsert');
        $this->dispatchEvent($entity, EntityEvent::POST_INSERT);

        $hasAutoIncrementPrimaryKey =
            1 === count($this->metadata->primaryKeys)
            && in_array($this->metadata->primaryKeys[0], $this->metadata->autoIncrementKeys, true);

        if ($hasAutoIncrementPrimaryKey && '' !== $id) {
            return $id;
        }

        return $this->extractPrimaryId($entity);
    }

    /**
     * @param array<int,TEntity> $entities
     * @return array<int,string>
     */
    public function insertMany(array $entities): array
    {
        if (empty($entities)) {
            return [];
        }

        return $this->connection->transaction(function () use ($entities): array {
            $ids = [];
            foreach ($entities as $entity) {
                $this->assertBulkEntity($entity);
                $ids[] = $this->insert($entity);
            }

            return $ids;
        });
    }

    /**
     * Upsert an entity and return affected rows.
     *
     * @param TEntity $entity
     * @param array<int,string> $conflictColumns
     * @param ?string $constraint
     * @param ?array<string,mixed> $updates
     * @return int
     */
    public function upsert(
        object $entity,
        array $conflictColumns = [],
        ?string $constraint = null,
        ?array $updates = null,
    ): int {
        $this->applyValidators($entity, ValidationType::CREATE);
        $this->dispatchEvent($entity, EntityEvent::PRE_INSERT);
        $this->callHook($entity, 'beforeInsert');
        $this->applyHooks($entity, 'create');

        $values = $this->extractValues($entity, true);
        $mappedConflictColumns = $this->mapConflictColumns($conflictColumns);

        if (null === $constraint && [] === $mappedConflictColumns) {
            $mappedConflictColumns = $this->metadata->primaryKeys;
        }

        if (null === $constraint && [] === $mappedConflictColumns) {
            throw new RuntimeException('Upsert requires conflict columns, a constraint, or a primary key.');
        }

        $payload = null === $updates
            ? $this->buildDefaultUpsertUpdates($values, $mappedConflictColumns)
            : $this->mapValues($updates);

        if ([] === $payload) {
            throw new RuntimeException('Upsert updates cannot be empty.');
        }

        $query = new InsertQuery($this->metadata->table);
        $query->values($values)->upsert($payload, $mappedConflictColumns, $constraint);

        $result = $this->connection->execute($query);
        $id = $this->connection->lastInsertId();
        $this->applyGeneratedId($entity, $id);
        $this->trackEntity($entity);
        $this->syncTrackedEntity($entity);
        $this->callHook($entity, 'afterInsert');
        $this->dispatchEvent($entity, EntityEvent::POST_INSERT);

        return $result;
    }

    /**
     * @param array<int,TEntity> $entities
     * @param array<int,string> $conflictColumns
     * @param ?array<string,mixed> $updates
     */
    public function upsertMany(
        array $entities,
        array $conflictColumns = [],
        ?string $constraint = null,
        ?array $updates = null,
    ): int {
        if (empty($entities)) {
            return 0;
        }

        return $this->connection->transaction(function () use ($entities, $conflictColumns, $constraint, $updates): int {
            $total = 0;
            foreach ($entities as $entity) {
                $this->assertBulkEntity($entity);
                $total += $this->upsert($entity, $conflictColumns, $constraint, $updates);
            }

            return $total;
        });
    }

    /**
     * Update an entity using either diff-based or full payload updates.
     *
     * @param TEntity $entity
     * @param bool $force If true, forces a full update even if the entity provides diffing.
     * @return int
     * @throws ValidationException If entity validation fails.
     * @throws RuntimeException If no primary key mapping is available.
     */
    public function save(object $entity, bool $force = false): int
    {
        if ($this->shouldInsert($entity)) {
            $this->insert($entity);
            return 1;
        }

        $this->applyValidators($entity, ValidationType::UPDATE);
        if (false === $force && $entity instanceof ProvidesDiff) {
            $changes = $entity->diff();
            if (empty($changes)) {
                return 0;
            }

            $this->applyHooks($entity, 'update', $changes);
            return $this->updateWithValues($entity, $changes);
        }

        if (empty($this->metadata->primaryKeys)) {
            throw new RuntimeException('Update requires a primary key.');
        }

        $criteria = [];
        foreach ($this->metadata->primaryKeys as $primaryKey) {
            $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
            $criteria[$property] = $entity->{$property} ?? null;
        }

        $condition = $this->buildCriteriaCondition($criteria);
        $this->applyHooks($entity, 'update');
        $values = $this->extractValues($entity, false);
        if (empty($values)) {
            return 0;
        }

        $this->dispatchEvent($entity, EntityEvent::PRE_UPDATE);
        $this->callHook($entity, 'beforeUpdate');
        $query = new UpdateQuery($this->metadata->table)
            ->values($values)
            ->where($condition);

        $result = $this->connection->execute($query);
        if ($result > 0) {
            $this->syncTrackedEntity($entity);
        }
        $this->callHook($entity, 'afterUpdate');
        $this->dispatchEvent($entity, EntityEvent::POST_UPDATE);

        return $result;
    }

    private function shouldInsert(object $entity): bool
    {
        if (empty($this->metadata->primaryKeys)) {
            return false;
        }

        foreach ($this->metadata->primaryKeys as $primaryKey) {
            $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
            if (!property_exists($entity, $property)) {
                return true;
            }

            $value = $entity->{$property} ?? null;
            if (null === $value || '' === $value) {
                return true;
            }

            if (in_array($primaryKey, $this->metadata->autoIncrementKeys, true) && (0 === $value || '0' === $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,TEntity> $entities
     */
    public function updateMany(array $entities): int
    {
        if (empty($entities)) {
            return 0;
        }

        return $this->connection->transaction(function () use ($entities): int {
            $total = 0;
            foreach ($entities as $entity) {
                $this->assertBulkEntity($entity);
                $total += $this->save($entity);
            }

            return $total;
        });
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateChanged(object $entity, array $values): int
    {
        $this->applyValidators($entity, ValidationType::UPDATE, $values);
        $this->applyHooks($entity, 'update', $values);
        return $this->updateWithValues($entity, $values);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function updateWithValues(object $entity, array $values): int
    {
        if (empty($this->metadata->primaryKeys)) {
            throw new RuntimeException('Update requires a primary key.');
        }

        if (empty($values)) {
            return 0;
        }

        $criteria = [];
        foreach ($this->metadata->primaryKeys as $primaryKey) {
            $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
            $criteria[$property] = $entity->{$property} ?? null;
        }

        $condition = $this->buildCriteriaCondition($criteria);
        $payload = $this->mapValues($values);

        $this->dispatchEvent($entity, EntityEvent::PRE_UPDATE);
        $this->callHook($entity, 'beforeUpdate');
        $query = new UpdateQuery($this->metadata->table)
            ->values($payload)
            ->where($condition);

        $result = $this->connection->execute($query);
        if ($result > 0) {
            $this->syncTrackedEntity($entity);
        }
        $this->callHook($entity, 'afterUpdate');
        $this->dispatchEvent($entity, EntityEvent::POST_UPDATE);

        return $result;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateWhere(array $values, Condition $condition): int
    {
        $query = new UpdateQuery($this->metadata->table)
            ->values($this->mapValues($values))
            ->where($condition);

        return $this->connection->execute($query);
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $criteria
     */
    public function updateBy(array $values, array $criteria): int
    {
        $condition = $this->buildCriteriaCondition($criteria);

        return $this->updateWhere($values, $condition);
    }

    /**
     * Delete an entity by primary key and trigger lifecycle hooks/events.
     *
     * @param TEntity $entity
     * @return int
     * @throws RuntimeException If no primary key mapping is available.
     */
    public function delete(object $entity): int
    {
        if (empty($this->metadata->primaryKeys)) {
            throw new RuntimeException('Delete requires a primary key.');
        }

        $criteria = [];
        foreach ($this->metadata->primaryKeys as $primaryKey) {
            $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
            $criteria[$property] = $entity->{$property} ?? null;
        }

        $condition = $this->buildCriteriaCondition($criteria);
        $query = new DeleteQuery($this->metadata->table)->where($condition);

        $this->dispatchEvent($entity, EntityEvent::PRE_DELETE);
        $this->callHook($entity, 'beforeDelete');
        $result = $this->connection->execute($query);
        $this->callHook($entity, 'afterDelete');
        $this->dispatchEvent($entity, EntityEvent::POST_DELETE);
        $this->forgetEntity($entity);

        return $result;
    }

    /**
     * @param array<int,TEntity> $entities
     */
    public function deleteMany(array $entities): int
    {
        if (empty($entities)) {
            return 0;
        }

        return $this->connection->transaction(function () use ($entities): int {
            $total = 0;
            foreach ($entities as $entity) {
                $this->assertBulkEntity($entity);
                $total += $this->delete($entity);
            }

            return $total;
        });
    }

    /**
     * Delete entities matching a condition and reset identity cache when needed.
     * @param Condition $condition Condition.
     * @return int
     */

    public function deleteWhere(Condition $condition): int
    {
        $query = new DeleteQuery($this->metadata->table)
            ->where($condition);

        $result = $this->connection->execute($query);
        if ($result > 0) {
            $this->identityMap = [];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function deleteBy(array $criteria): int
    {
        $condition = $this->buildCriteriaCondition($criteria);

        return $this->deleteWhere($condition);
    }

    /**
     * Attach related rows to a many-to-many relation.
     *
     * @param TEntity $entity
     * @param mixed $related Related identifiers or payload records.
     * @param array<string,mixed> $pivot Pivot values applied to every record unless overridden per item.
     * @return array{attached:int,updated:int,skipped:int}
     */
    public function attach(
        object $entity,
        string $relationName,
        mixed $related,
        array $pivot = [],
        string $onDuplicate = self::DUPLICATE_BEHAVIOR_ERROR,
    ): array {
        $relation = $this->requireRelationType($relationName, [RelationMetadata::TYPE_BELONGS_TO_MANY]);
        $this->assertDuplicateBehavior($onDuplicate);

        $context = $this->buildBelongsToManyWriteContext($entity, $relation);
        $records = $this->normalizeManyToManyRecords($related, $pivot, $context['relatedMetadata'], $context['relatedKey']);
        if ([] === $records) {
            return ['attached' => 0, 'updated' => 0, 'skipped' => 0];
        }

        return $this->connection->transaction(function () use ($context, $records, $onDuplicate): array {
            $existing = $this->findExistingPivotLinks($context, array_keys($records));
            $attached = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($records as $relatedId => $record) {
                if (isset($existing[(string) $relatedId])) {
                    if (self::DUPLICATE_BEHAVIOR_ERROR === $onDuplicate) {
                        throw new RuntimeException(
                            'Relation attach duplicate detected for relation "'
                            . $context['relation']->name
                            . '" and related key '
                            . (string) $relatedId
                            . '.',
                        );
                    }

                    if (self::DUPLICATE_BEHAVIOR_IGNORE === $onDuplicate || [] === $record['pivot']) {
                        $skipped += 1;
                        continue;
                    }

                    $updated += $this->updatePivotLink($context, $relatedId, $record['pivot']);
                    continue;
                }

                $attached += $this->insertPivotLink($context, $relatedId, $record['pivot']);
            }

            return [
                'attached' => $attached,
                'updated' => $updated,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * Detach related rows from a many-to-many relation.
     *
     * @param TEntity $entity
     * @param mixed $related Related identifiers to detach, or null to detach all.
     */
    public function detach(object $entity, string $relationName, mixed $related = null): int
    {
        $relation = $this->requireRelationType($relationName, [RelationMetadata::TYPE_BELONGS_TO_MANY]);
        $context = $this->buildBelongsToManyWriteContext($entity, $relation);

        $query = new DeleteQuery($context['pivotTable']);
        $condition = Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']);

        if (null !== $related) {
            $records = $this->normalizeManyToManyRecords(
                $related,
                [],
                $context['relatedMetadata'],
                $context['relatedKey'],
            );
            if ([] === $records) {
                return 0;
            }

            $condition = Condition::and(
                $condition,
                Condition::in($context['relatedPivotKey'], array_keys($records)),
            );
        }

        $query->where($condition);

        return $this->connection->execute($query);
    }

    /**
     * Sync many-to-many links to exactly match the provided related records.
     *
     * @param TEntity $entity
     * @param mixed $related Related identifiers or payload records.
     * @return array{attached:int,updated:int,detached:int}
     */
    public function sync(object $entity, string $relationName, mixed $related): array
    {
        $relation = $this->requireRelationType($relationName, [RelationMetadata::TYPE_BELONGS_TO_MANY]);
        $context = $this->buildBelongsToManyWriteContext($entity, $relation);

        $records = $this->normalizeManyToManyRecords($related, [], $context['relatedMetadata'], $context['relatedKey']);

        return $this->connection->transaction(function () use ($context, $records): array {
            $existing = $this->fetchAllExistingPivotLinks($context);
            $targetIds = array_keys($records);
            $existingIds = array_keys($existing);

            $toDetach = array_values(array_diff($existingIds, $targetIds));
            $detached = 0;
            if ([] !== $toDetach) {
                $detached = $this->connection->execute(
                    new DeleteQuery($context['pivotTable'])->where(
                        Condition::and(
                            Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']),
                            Condition::in($context['relatedPivotKey'], $toDetach),
                        ),
                    ),
                );
            }

            $attached = 0;
            $updated = 0;

            foreach ($records as $relatedId => $record) {
                if (!isset($existing[(string) $relatedId])) {
                    $attached += $this->insertPivotLink($context, $relatedId, $record['pivot']);
                    continue;
                }

                if ([] !== $record['pivot']) {
                    $updated += $this->updatePivotLink($context, $relatedId, $record['pivot']);
                }
            }

            return [
                'attached' => $attached,
                'updated' => $updated,
                'detached' => $detached,
            ];
        });
    }

    /**
     * Toggle many-to-many links: existing links are detached, missing links are attached.
     *
     * @param TEntity $entity
     * @param mixed $related Related identifiers or payload records.
     * @param array<string,mixed> $pivot Pivot values applied to every attached record unless overridden per item.
     * @return array{attached:int,detached:int}
     */
    public function toggle(object $entity, string $relationName, mixed $related, array $pivot = []): array
    {
        $relation = $this->requireRelationType($relationName, [RelationMetadata::TYPE_BELONGS_TO_MANY]);
        $context = $this->buildBelongsToManyWriteContext($entity, $relation);
        $records = $this->normalizeManyToManyRecords($related, $pivot, $context['relatedMetadata'], $context['relatedKey']);
        if ([] === $records) {
            return ['attached' => 0, 'detached' => 0];
        }

        return $this->connection->transaction(function () use ($context, $records): array {
            $existing = $this->findExistingPivotLinks($context, array_keys($records));
            $detachedIds = [];
            $attachIds = [];

            foreach ($records as $relatedId => $record) {
                if (isset($existing[(string) $relatedId])) {
                    $detachedIds[] = $relatedId;
                    continue;
                }

                $attachIds[(string) $relatedId] = $record;
            }

            $detached = 0;
            if ([] !== $detachedIds) {
                $detached = $this->connection->execute(
                    new DeleteQuery($context['pivotTable'])->where(
                        Condition::and(
                            Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']),
                            Condition::in($context['relatedPivotKey'], $detachedIds),
                        ),
                    ),
                );
            }

            $attached = 0;
            foreach ($attachIds as $relatedId => $record) {
                $attached += $this->insertPivotLink($context, $relatedId, $record['pivot']);
            }

            return [
                'attached' => $attached,
                'detached' => $detached,
            ];
        });
    }

    /**
     * Save an existing related entity for a has-one or has-many relation.
     *
     * @param TEntity $entity
     */
    public function saveRelated(object $entity, string $relationName, object $relatedEntity): int
    {
        $this->assertBulkEntity($entity);
        $relation = $this->requireRelationType($relationName, [
            RelationMetadata::TYPE_HAS_ONE,
            RelationMetadata::TYPE_HAS_MANY,
        ]);

        if (!$relatedEntity instanceof $relation->target) {
            throw new RuntimeException('Related entity must be instance of ' . $relation->target . '.');
        }

        $parentValue = $this->requireLocalKeyValue($entity, $relation->localKey, $relation->name);
        $relatedRepo = $this->repositoryFor($relation->target);
        $relatedMeta = $relatedRepo->metadata;
        $foreignColumn = $this->requireMappedRelationColumn($relatedMeta, $relation->foreignKey, $relation->name, 'foreign');
        $foreignProperty = $this->requireMappedRelationProperty(
            $relatedMeta,
            $foreignColumn,
            $relation->name,
            'foreign',
        );

        if (!property_exists($relatedEntity, $foreignProperty)) {
            throw new RuntimeException(
                'Relation foreign key property "' . $foreignProperty . '" is missing on related entity.',
            );
        }

        $relatedEntity->{$foreignProperty} = $parentValue;
        $saved = $relatedRepo->save($relatedEntity);
        $this->assignRelatedOnParent($entity, $relation, $relatedEntity);

        return $saved;
    }

    /**
     * Create and persist a related entity for a has-one or has-many relation.
     *
     * @param TEntity $entity
     * @param array<string,mixed> $values
     */
    public function createRelated(object $entity, string $relationName, array $values): object
    {
        $this->assertBulkEntity($entity);
        $relation = $this->requireRelationType($relationName, [
            RelationMetadata::TYPE_HAS_ONE,
            RelationMetadata::TYPE_HAS_MANY,
        ]);

        $parentValue = $this->requireLocalKeyValue($entity, $relation->localKey, $relation->name);
        $relatedRepo = $this->repositoryFor($relation->target);
        $relatedMeta = $relatedRepo->metadata;
        $foreignColumn = $this->requireMappedRelationColumn($relatedMeta, $relation->foreignKey, $relation->name, 'foreign');
        $foreignProperty = $this->requireMappedRelationProperty(
            $relatedMeta,
            $foreignColumn,
            $relation->name,
            'foreign',
        );

        $relatedEntity = new $relation->target();
        $this->assignEntityValues($relatedEntity, $relatedMeta, $values);

        if (!property_exists($relatedEntity, $foreignProperty)) {
            throw new RuntimeException(
                'Relation foreign key property "' . $foreignProperty . '" is missing on related entity.',
            );
        }

        $relatedEntity->{$foreignProperty} = $parentValue;
        $relatedRepo->insert($relatedEntity);
        $this->assignRelatedOnParent($entity, $relation, $relatedEntity);

        return $relatedEntity;
    }

    /**
     * Execute with trashed for this entity repository.
     * @return self
     */

    public function withTrashed(): self
    {
        $clone = clone $this;
        $clone->includeTrashed = true;
        $clone->onlyTrashed = false;

        return $clone;
    }

    /**
     * Execute only trashed for this entity repository.
     * @return self
     */

    public function onlyTrashed(): self
    {
        $clone = clone $this;
        $clone->includeTrashed = true;
        $clone->onlyTrashed = true;

        return $clone;
    }

    public function withHydrateValidation(): self
    {
        $clone = clone $this;
        $clone->validateOnHydrate = true;

        return $clone;
    }

    public function withoutHydrateValidation(): self
    {
        $clone = clone $this;
        $clone->validateOnHydrate = false;

        return $clone;
    }

    /**
     * Register a local scope callback on a cloned repository instance.
     *
     * @param string $name Scope name.
     * @param callable $callback Scope callback receiving repository and query.
     * @return self
     * @throws RuntimeException If the scope name is empty.
     */
    public function scope(string $name, callable $callback): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('Scope name is required.');
        }

        $clone = clone $this;
        $clone->localScopes[$name] = $callback;

        return $clone;
    }

    /**
     * Register an existing repository method as a named local scope on a clone.
     *
     * @param string $name Scope method name.
     * @return self
     * @throws RuntimeException If the scope name is empty or method does not exist.
     */
    public function applyScope(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('Scope name is required.');
        }

        if (!method_exists($this, $name)) {
            throw new RuntimeException('Unknown scope: ' . $name);
        }

        $clone = clone $this;
        $clone->localScopes[$name] = [$clone, $name];

        return $clone;
    }

    /**
     * @param array<int,string> $allowedTypes
     */
    private function requireRelationType(string $relationName, array $allowedTypes): RelationMetadata
    {
        $name = trim($relationName);
        if ('' === $name) {
            throw new RuntimeException('Relation name is required.');
        }

        $relation = $this->metadata->relationFor($name);
        if (null === $relation) {
            throw new RuntimeException('Unknown relation: ' . $name);
        }

        if (!in_array($relation->type, $allowedTypes, true)) {
            throw new RuntimeException(
                'Relation "' . $name . '" does not support this write operation.',
            );
        }

        return $relation;
    }

    private function assertDuplicateBehavior(string $behavior): void
    {
        if (in_array(
            $behavior,
            [
                self::DUPLICATE_BEHAVIOR_ERROR,
                self::DUPLICATE_BEHAVIOR_IGNORE,
                self::DUPLICATE_BEHAVIOR_UPDATE,
            ],
            true,
        )) {
            return;
        }

        throw new RuntimeException(
            'Unsupported duplicate behavior "'
            . $behavior
            . '". Allowed values are: '
            . self::DUPLICATE_BEHAVIOR_ERROR
            . ', '
            . self::DUPLICATE_BEHAVIOR_IGNORE
            . ', '
            . self::DUPLICATE_BEHAVIOR_UPDATE
            . '.',
        );
    }

    /**
     * @param TEntity $entity
     * @return array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * }
     */
    private function buildBelongsToManyWriteContext(object $entity, RelationMetadata $relation): array
    {
        $this->assertBulkEntity($entity);

        $pivotTable = trim((string) $relation->pivotTable);
        $foreignPivotKey = trim((string) $relation->foreignPivotKey);
        $relatedPivotKey = trim((string) $relation->relatedPivotKey);
        $relatedKey = trim((string) ($relation->relatedKey ?? 'id'));

        if ('' === $pivotTable || '' === $foreignPivotKey || '' === $relatedPivotKey) {
            throw new RuntimeException(
                'Many-to-many relation "' . $relation->name . '" is missing pivot table/key configuration.',
            );
        }

        $relatedMetadata = $this->metadataFactory->fromClass($relation->target);
        $parentKeyValue = $this->requireLocalKeyValue($entity, $relation->localKey, $relation->name);

        $relatedColumn = $this->requireMappedRelationColumn($relatedMetadata, $relatedKey, $relation->name, 'related');
        $this->requireMappedRelationProperty($relatedMetadata, $relatedColumn, $relation->name, 'related');

        $allowedPivotColumns = [];
        foreach ($relation->pivotColumns as $column) {
            $name = trim((string) $column);
            if ('' === $name) {
                continue;
            }
            $allowedPivotColumns[] = $name;
        }

        return [
            'relation' => $relation,
            'pivotTable' => $pivotTable,
            'foreignPivotKey' => $foreignPivotKey,
            'relatedPivotKey' => $relatedPivotKey,
            'parentKeyValue' => $parentKeyValue,
            'relatedKey' => $relatedColumn,
            'relatedMetadata' => $relatedMetadata,
            'allowedPivotColumns' => array_values(array_unique($allowedPivotColumns)),
        ];
    }

    /**
     * @param TEntity $entity
     */
    private function requireLocalKeyValue(object $entity, string $localKey, string $relationName): mixed
    {
        $column = $this->requireMappedRelationColumn($this->metadata, $localKey, $relationName, 'local');
        $property = $this->requireMappedRelationProperty($this->metadata, $column, $relationName, 'local');
        if (!property_exists($entity, $property)) {
            throw new RuntimeException(
                'Relation "' . $relationName . '" local key property "' . $property . '" is missing on parent entity.',
            );
        }

        $value = $entity->{$property} ?? null;
        if (null === $value) {
            throw new RuntimeException(
                'Relation "' . $relationName . '" local key "' . $property . '" cannot be null.',
            );
        }

        if (in_array($column, $this->metadata->autoIncrementKeys, true) && (0 === $value || '0' === $value || '' === $value)) {
            throw new RuntimeException(
                'Relation "' . $relationName . '" local key "' . $property . '" must reference a persisted parent.',
            );
        }

        return $value;
    }

    private function requireMappedRelationColumn(
        EntityMetadata $metadata,
        string $key,
        string $relationName,
        string $side,
    ): string {
        $name = trim($key);
        if ('' === $name) {
            throw new RuntimeException('Relation "' . $relationName . '" ' . $side . ' key is required.');
        }

        $column = $metadata->columnFor($name);
        if (null !== $column) {
            return $column;
        }

        $property = $metadata->propertyFor($name);
        if (null !== $property) {
            return $name;
        }

        throw new RuntimeException(
            'Relation "' . $relationName . '" ' . $side . ' key "' . $name . '" is not mapped on ' . $metadata->className . '.',
        );
    }

    private function requireMappedRelationProperty(
        EntityMetadata $metadata,
        string $column,
        string $relationName,
        string $side,
    ): string {
        $property = $metadata->propertyFor($column);
        if (null === $property) {
            throw new RuntimeException(
                'Relation "' . $relationName . '" ' . $side . ' key "' . $column . '" is not mapped on ' . $metadata->className . '.',
            );
        }

        return $property;
    }

    /**
     * @param array<string,mixed> $defaultPivot
     * @return array<string,array{id:mixed,pivot:array<string,mixed>}>
     */
    private function normalizeManyToManyRecords(
        mixed $related,
        array $defaultPivot,
        EntityMetadata $relatedMeta,
        string $relatedKey,
    ): array {
        $relatedProperty = $this->resolvePropertyName($relatedMeta, $relatedKey);

        $items = [];
        if (is_array($related)) {
            if ([] === $related) {
                return [];
            }

            $isSingleAssoc =
                !array_is_list($related) && (array_key_exists($relatedProperty, $related) || array_key_exists($relatedKey, $related));

            if ($isSingleAssoc) {
                $items[] = $related;
            } elseif (!array_is_list($related)) {
                foreach ($related as $id => $payload) {
                    $entry = is_array($payload) ? $payload : [];
                    if (!array_key_exists($relatedProperty, $entry) && !array_key_exists($relatedKey, $entry)) {
                        $entry[$relatedKey] = $id;
                    }
                    $items[] = $entry;
                }
            } else {
                $items = $related;
            }
        } else {
            $items[] = $related;
        }

        $normalized = [];
        foreach ($items as $item) {
            $id = $this->extractManyToManyRelatedId($item, $relatedProperty, $relatedKey);
            if (null === $id || '' === $id) {
                throw new RuntimeException('Related identifier for many-to-many write cannot be empty.');
            }

            $pivot = $this->extractManyToManyPivotPayload($item);
            $mergedPivot = array_merge($defaultPivot, $pivot);

            $key = (string) $id;
            if (isset($normalized[$key])) {
                throw new RuntimeException('Duplicate related identifier provided for many-to-many write: ' . $key . '.');
            }

            $normalized[$key] = [
                'id' => $id,
                'pivot' => $mergedPivot,
            ];
        }

        return $normalized;
    }

    private function extractManyToManyRelatedId(mixed $item, string $relatedProperty, string $relatedKey): mixed
    {
        if (is_scalar($item)) {
            return $item;
        }

        if (is_object($item)) {
            if (property_exists($item, $relatedProperty)) {
                return $item->{$relatedProperty};
            }

            if ($relatedProperty !== $relatedKey && property_exists($item, $relatedKey)) {
                return $item->{$relatedKey};
            }

            throw new RuntimeException(
                'Related entity is missing key property "' . $relatedProperty . '" for many-to-many write.',
            );
        }

        if (is_array($item)) {
            if (array_key_exists($relatedProperty, $item)) {
                return $item[$relatedProperty];
            }

            if (array_key_exists($relatedKey, $item)) {
                return $item[$relatedKey];
            }

            throw new RuntimeException(
                'Related payload is missing key "' . $relatedProperty . '" for many-to-many write.',
            );
        }

        throw new RuntimeException('Unsupported related identifier payload type for many-to-many write.');
    }

    /**
     * @return array<string,mixed>
     */
    private function extractManyToManyPivotPayload(mixed $item): array
    {
        if (!is_array($item)) {
            return [];
        }

        if (!array_key_exists('pivot', $item)) {
            return [];
        }

        $pivot = $item['pivot'];
        if (!is_array($pivot)) {
            throw new RuntimeException('Pivot payload must be an array.');
        }

        return $pivot;
    }

    /**
     * @param array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * } $context
     * @param array<int|string,mixed> $relatedIds
     * @return array<string,bool>
     */
    private function findExistingPivotLinks(array $context, array $relatedIds): array
    {
        if ([] === $relatedIds) {
            return [];
        }

        $query = new SelectQuery($context['pivotTable'])
            ->select([$context['relatedPivotKey']])
            ->where(
                Condition::and(
                    Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']),
                    Condition::in($context['relatedPivotKey'], array_values($relatedIds)),
                ),
            );

        $rows = $this->connection->fetchAll($query);
        $existing = [];
        foreach ($rows as $row) {
            if (!array_key_exists($context['relatedPivotKey'], $row)) {
                continue;
            }

            $existing[(string) $row[$context['relatedPivotKey']]] = true;
        }

        return $existing;
    }

    /**
     * @param array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * } $context
     * @return array<string,bool>
     */
    private function fetchAllExistingPivotLinks(array $context): array
    {
        $query = new SelectQuery($context['pivotTable'])
            ->select([$context['relatedPivotKey']])
            ->where(Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']));

        $rows = $this->connection->fetchAll($query);
        $existing = [];
        foreach ($rows as $row) {
            if (!array_key_exists($context['relatedPivotKey'], $row)) {
                continue;
            }

            $existing[(string) $row[$context['relatedPivotKey']]] = true;
        }

        return $existing;
    }

    /**
     * @param array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * } $context
     * @param array<string,mixed> $pivot
     */
    private function insertPivotLink(array $context, mixed $relatedId, array $pivot): int
    {
        $values = [
            $context['foreignPivotKey'] => $context['parentKeyValue'],
            $context['relatedPivotKey'] => $relatedId,
        ];

        foreach ($this->normalizePivotPayload($pivot, $context) as $column => $value) {
            $values[$column] = $value;
        }

        return $this->connection->execute(
            new InsertQuery($context['pivotTable'])->values($values),
        );
    }

    /**
     * @param array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * } $context
     * @param array<string,mixed> $pivot
     */
    private function updatePivotLink(array $context, mixed $relatedId, array $pivot): int
    {
        $values = $this->normalizePivotPayload($pivot, $context);
        if ([] === $values) {
            return 0;
        }

        return $this->connection->execute(
            new UpdateQuery($context['pivotTable'])
                ->values($values)
                ->where(
                    Condition::and(
                        Condition::equals($context['foreignPivotKey'], $context['parentKeyValue']),
                        Condition::equals($context['relatedPivotKey'], $relatedId),
                    ),
                ),
        );
    }

    /**
     * @param array<string,mixed> $pivot
     * @param array{
     *     relation:RelationMetadata,
     *     pivotTable:string,
     *     foreignPivotKey:string,
     *     relatedPivotKey:string,
     *     parentKeyValue:mixed,
     *     relatedKey:string,
     *     relatedMetadata:EntityMetadata,
     *     allowedPivotColumns:array<int,string>
     * } $context
     * @return array<string,mixed>
     */
    private function normalizePivotPayload(array $pivot, array $context): array
    {
        if ([] === $pivot) {
            return [];
        }

        $allowed = array_flip($context['allowedPivotColumns']);
        if ([] === $allowed) {
            throw new RuntimeException(
                'Relation "' . $context['relation']->name . '" does not allow pivot payload columns.',
            );
        }

        $normalized = [];
        foreach ($pivot as $column => $value) {
            if (!is_string($column)) {
                throw new RuntimeException('Pivot payload keys must be strings.');
            }

            $name = trim($column);
            if ('' === $name) {
                continue;
            }

            if (!array_key_exists($name, $allowed)) {
                throw new RuntimeException(
                    'Pivot column "' . $name . '" is not configured on relation "' . $context['relation']->name . '".',
                );
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function assignEntityValues(object $entity, EntityMetadata $metadata, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Related entity values must use string keys.');
            }

            $property = $metadata->propertyFor($key) ?? $key;
            if (!property_exists($entity, $property)) {
                throw new RuntimeException(
                    'Unknown related property "' . $property . '" for entity ' . $metadata->className . '.',
                );
            }

            $entity->{$property} = $value;
        }
    }

    /**
     * @param TEntity $entity
     */
    private function assignRelatedOnParent(object $entity, RelationMetadata $relation, object $relatedEntity): void
    {
        if (RelationMetadata::TYPE_HAS_ONE === $relation->type) {
            $entity->{$relation->name} = $relatedEntity;
            return;
        }

        $current = $entity->{$relation->name} ?? null;
        if (is_array($current)) {
            $current[] = $relatedEntity;
            $entity->{$relation->name} = $current;
            return;
        }

        $entity->{$relation->name} = [$relatedEntity];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractValues(object $entity, bool $skipAutoIncrement): array
    {
        $values = [];
        foreach ($this->metadata->columnsByProperty as $property => $column) {
            if ($skipAutoIncrement && in_array($column, $this->metadata->autoIncrementKeys, true)) {
                $value = $entity->{$property} ?? null;
                if (null === $value || '' === $value || 0 === $value) {
                    continue;
                }
            }

            $value = $entity->{$property} ?? null;
            $values[$column] = $this->encodeValue($property, $value);
        }

        return $values;
    }

    /**
     * @param array<string,mixed>|null $values
     */
    private function applyHooks(object $entity, string $event, ?array &$values = null): void
    {
        foreach ($this->metadata->hooksByProperty as $property => $hooks) {
            if (!isset($hooks[$event])) {
                continue;
            }

            $callable = $hooks[$event];
            $hasValue = null !== $values ? array_key_exists($property, $values) : false;
            if ($hasValue) {
                continue;
            }

            if (!property_exists($entity, $property)) {
                continue;
            }

            $value = $entity->{$property} ?? null;
            if ('create' === $event && null !== $value && '' !== $value) {
                continue;
            }

            $resolved = $this->resolveValueCallable($callable);
            if (null === $resolved) {
                continue;
            }

            $nextValue = $this->invokeValueCallable($resolved, $entity, $property, $event);
            $entity->{$property} = $nextValue;
            if (null !== $values) {
                $values[$property] = $nextValue;
            }
        }
    }

    private function resolveValueCallable(string $callable): ?callable
    {
        $callable = trim($callable);
        if ('' === $callable) {
            return null;
        }

        if (str_contains($callable, '::')) {
            [$class, $method] = explode('::', $callable, 2);
            if ('' === trim($class) || '' === trim($method)) {
                return null;
            }

            if (!class_exists($class)) {
                return null;
            }

            if (!is_callable([$class, $method])) {
                return null;
            }

            return [$class, $method];
        }

        if (is_callable($callable)) {
            return $callable;
        }

        if (function_exists($callable) && is_callable($callable)) {
            return $callable;
        }

        return null;
    }

    /**
     * @param callable $callable
     */
    private function invokeValueCallable(callable $callable, object $entity, string $property, string $event): mixed
    {
        $args = [$entity, $property, $event];
        $reflection = $this->reflectCallable($callable);
        if (null === $reflection) {
            return $callable(...$args);
        }

        $required = $reflection->getNumberOfRequiredParameters();
        if (!$reflection->isVariadic() && $required > 3) {
            throw new RuntimeException('Column hook callable requires too many arguments.');
        }

        if ($reflection->isVariadic()) {
            return $callable(...$args);
        }

        $count = min($reflection->getNumberOfParameters(), 3);
        return $callable(...array_slice($args, 0, $count));
    }

    private function reflectCallable(callable $callable): ?ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            $target = $callable[0] ?? null;
            $method = $callable[1] ?? null;
            if ((is_object($target) || is_string($target)) && is_string($method)) {
                return new ReflectionMethod($target, $method);
            }

            return null;
        }

        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                if ('' !== trim($class) && '' !== trim($method)) {
                    return new ReflectionMethod($class, $method);
                }
            }

            return new ReflectionFunction($callable);
        }

        if ($callable instanceof \Closure) {
            return new ReflectionFunction($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return null;
    }

    /**
     * @param array<string|int,mixed> $criteria
     */
    private function buildCriteriaCondition(array $criteria): Condition
    {
        $condition = null;
        foreach ($criteria as $property => $value) {
            if ($value instanceof Condition) {
                $current = $value;
                $condition = null === $condition ? $current : Condition::and($condition, $current);
                continue;
            }

            if (is_int($property)) {
                if (!$value instanceof Condition) {
                    throw new RuntimeException('Criteria list values must be Condition instances.');
                }
                $current = $value;
                $condition = null === $condition ? $current : Condition::and($condition, $current);
                continue;
            }

            $column = $this->metadata->columnFor($property) ?? (string) $property;
            $current = is_array($value) ? Condition::in($column, $value) : Condition::equals($column, $value);
            $condition = null === $condition ? $current : Condition::and($condition, $current);
        }

        if (null === $condition) {
            throw new RuntimeException('Criteria is required.');
        }

        return $condition;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function mapValues(array $values): array
    {
        $mapped = [];
        foreach ($values as $property => $value) {
            $column = $this->metadata->columnFor($property) ?? $property;
            $mapped[$column] = $this->encodeValue($property, $value);
        }

        return $mapped;
    }

    /**
     * @param array<int,string> $conflictColumns
     * @return array<int,string>
     */
    private function mapConflictColumns(array $conflictColumns): array
    {
        $mapped = [];
        foreach ($conflictColumns as $column) {
            $name = trim($column);
            if ('' === $name) {
                continue;
            }

            $mapped[] = $this->metadata->columnFor($name) ?? $name;
        }

        return array_values(array_unique($mapped));
    }

    /**
     * @param array<string,mixed> $values
     * @param array<int,string> $conflictColumns
     * @return array<string,mixed>
     */
    private function buildDefaultUpsertUpdates(array $values, array $conflictColumns): array
    {
        $updates = [];
        $conflictLookup = array_flip($conflictColumns);
        foreach (array_keys($values) as $column) {
            if (array_key_exists($column, $conflictLookup)) {
                continue;
            }

            if (in_array($column, $this->metadata->autoIncrementKeys, true)) {
                continue;
            }

            $updates[$column] = UpsertValue::inserted($column);
        }

        return $updates;
    }

    /**
     * @param array<int,string> $columns
     * @return array<int,string>
     */
    private function mapSelectableColumns(array $columns): array
    {
        $mapped = [];
        foreach ($columns as $column) {
            $mapped[] = $this->mapSelectableColumn($column);
        }

        return $mapped;
    }

    /**
     * @param array<int,string>|null $columns
     * @return array{columns:array<int,string>,full:bool}
     */
    private function resolveSelectColumns(?array $columns): array
    {
        if (null === $columns || [] === $columns) {
            return [
                'columns' => array_values($this->metadata->columnsByProperty),
                'full' => true,
            ];
        }

        $mapped = $this->mapSelectableColumns($columns);

        return [
            'columns' => $mapped,
            'full' => $this->isFullColumnSelection($mapped),
        ];
    }

    /**
     * @param array<int,string> $columns
     */
    private function isFullColumnSelection(array $columns): bool
    {
        foreach ($columns as $column) {
            if (!('*' === $column || str_ends_with($column, '.*'))) {
                continue;
            }

            return true;
        }

        $expected = array_values($this->metadata->columnsByProperty);
        $selected = array_values(array_unique($columns));
        sort($expected);
        sort($selected);

        return $expected === $selected;
    }

    /**
     * @param array<int,string> $relations
     */
    private function assertRelationsSelectable(array $relations, bool $fullSelection): void
    {
        if (!empty($relations) && !$fullSelection) {
            throw new RuntimeException('Relations cannot be loaded when selecting partial columns.');
        }
    }

    private function mapSelectableColumn(string $column): string
    {
        return $this->mapSelectableColumnWithMetadata($this->metadata, $column);
    }

    private function mapSelectableColumnWithMetadata(EntityMetadata $metadata, string $column): string
    {
        $column = trim($column);
        if ('' === $column) {
            throw new RuntimeException('Select column is required.');
        }

        if ($this->isExpressionColumn($column)) {
            throw new RuntimeException('Select expressions must use selectRaw or selectAs with raw SQL.');
        }

        if ('*' === $column || str_contains($column, '.')) {
            return $column;
        }

        return $metadata->columnFor($column) ?? $column;
    }

    private function isExpressionColumn(string $column): bool
    {
        return str_contains($column, '(') || str_contains($column, ')') || str_contains($column, ' ');
    }

    /**
     * @param array<int|string,string> $orderBy
     */
    private function applyOrderBy(SelectQuery $query, array $orderBy): void
    {
        foreach ($orderBy as $column => $direction) {
            if (is_int($column)) {
                $query->orderBy($this->mapSelectableColumn((string) $direction));
                continue;
            }

            $query->orderBy($this->mapSelectableColumn($column), is_string($direction) ? $direction : 'ASC');
        }
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        return 'DESC' === $direction ? 'DESC' : 'ASC';
    }

    private function resolveCursorValue(object $entity, string $column): int
    {
        $property = $this->metadata->propertyFor($column) ?? $column;
        if (!property_exists($entity, $property)) {
            throw new RuntimeException('Cursor column is missing from entity.');
        }

        $value = $entity->{$property} ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Cursor column value must be an int.');
        }

        return $value;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function normalizePagination(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        return [$page, $perPage, $offset];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,TEntity>
     */
    private function hydrateRows(array $rows, bool $useIdentityMap = true): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrateRow($row, $useIdentityMap);
        }

        return $entities;
    }

    /**
     * @param array<string,mixed> $row
     * @return TEntity
     */
    private function hydrateRow(array $row, bool $useIdentityMap = true): object
    {
        if ($useIdentityMap) {
            $identityKey = $this->identityKeyFromRow($row);
            if (null !== $identityKey && isset($this->identityMap[$identityKey])) {
                $entity = $this->hydrator->hydrateInto($this->identityMap[$identityKey], $this->metadata, $row);
                if ($this->validateOnHydrate) {
                    $this->applyValidators($entity, ValidationType::HYDRATE);
                }

                return $entity;
            }

            $entity = $this->hydrator->hydrate($this->className, $this->metadata, $row);
            if ($this->validateOnHydrate) {
                $this->applyValidators($entity, ValidationType::HYDRATE);
            }
            if (null !== $identityKey) {
                $this->identityMap[$identityKey] = $entity;
            }

            return $entity;
        }

        $entity = $this->hydrator->hydrate($this->className, $this->metadata, $row);
        if ($this->validateOnHydrate) {
            $this->applyValidators($entity, ValidationType::HYDRATE);
        }

        return $entity;
    }

    /**
     * @param array<string,mixed> $row
     * @return TEntity
     */
    private function hydrateRowWithMetadata(string $className, EntityMetadata $metadata, array $row): object
    {
        return $this->hydrator->hydrate($className, $metadata, $row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function identityKeyFromRow(array $row): ?string
    {
        if (empty($this->metadata->primaryKeys)) {
            return null;
        }

        $parts = [];
        foreach ($this->metadata->primaryKeys as $column) {
            $property = $this->metadata->propertyFor($column) ?? $column;
            if (array_key_exists($column, $row)) {
                $value = $row[$column];
            } elseif (array_key_exists($property, $row)) {
                $value = $row[$property];
            } else {
                return null;
            }

            if (null === $value) {
                return null;
            }

            $parts[] = (string) $value;
        }

        return implode('|', $parts);
    }

    /**
     * @param TEntity $entity
     */
    private function identityKeyFromEntity(object $entity): ?string
    {
        if (empty($this->metadata->primaryKeys)) {
            return null;
        }

        $parts = [];
        foreach ($this->metadata->primaryKeys as $column) {
            $property = $this->metadata->propertyFor($column) ?? $column;
            if (!property_exists($entity, $property)) {
                return null;
            }

            $value = $entity->{$property} ?? null;
            if (null === $value) {
                return null;
            }

            $parts[] = (string) $value;
        }

        return implode('|', $parts);
    }

    private function identityKeyFromId(mixed $id): ?string
    {
        if (1 !== count($this->metadata->primaryKeys)) {
            return null;
        }

        if (null === $id || '' === $id) {
            return null;
        }

        return (string) $id;
    }

    /**
     * @param TEntity $entity
     */
    private function trackEntity(object $entity): void
    {
        $identityKey = $this->identityKeyFromEntity($entity);
        if (null === $identityKey) {
            return;
        }

        $this->identityMap[$identityKey] = $entity;
    }

    private function syncTrackedEntity(object $entity): void
    {
        if ($entity instanceof TracksChanges) {
            $entity->markClean();
        }
    }

    private function assertBulkEntity(object $entity): void
    {
        if (!$entity instanceof $this->className) {
            throw new RuntimeException('Bulk operation entity must be instance of ' . $this->className . '.');
        }
    }

    /**
     * @param TEntity $entity
     */
    private function forgetEntity(object $entity): void
    {
        $identityKey = $this->identityKeyFromEntity($entity);
        if (null === $identityKey) {
            return;
        }

        unset($this->identityMap[$identityKey]);
    }

    private function encodeValue(string $property, mixed $value): mixed
    {
        if ($value instanceof RawExpression || $value instanceof UpsertValue) {
            return $value;
        }

        $transform = $this->metadata->transformFor($property);
        if (null === $transform) {
            return $value;
        }

        return $transform(TransformType::ENCODE, $value);
    }

    /**
     * @param array<string,mixed>|null $values
     */
    private function applyValidators(object $entity, ValidationType $type, ?array $values = null): void
    {
        foreach ($this->metadata->validatorsByProperty as $property => $validators) {
            if (!property_exists($entity, $property)) {
                continue;
            }

            $value = $values[$property] ?? $entity->{$property} ?? null;
            foreach ($validators as $validator) {
                $callable = null;
                $validatorTypes = [];

                if (is_callable($validator)) {
                    $callable = $validator;
                } elseif (is_array($validator)) {
                    $candidate = $validator['callable'] ?? null;
                    if (is_callable($candidate)) {
                        $callable = $candidate;
                    }

                    $candidateTypes = $validator['types'] ?? [];
                    if (is_array($candidateTypes)) {
                        $validatorTypes = array_values(array_filter(
                            $candidateTypes,
                            static fn(mixed $item): bool => $item instanceof ValidationType,
                        ));
                    }
                }

                if (!is_callable($callable)) {
                    continue;
                }

                if ([] !== $validatorTypes && !in_array($type, $validatorTypes, true)) {
                    continue;
                }

                $this->invokeValidator($callable, $type, $value, $property, $entity);
            }
        }
    }

    private function invokeValidator(
        callable $validator,
        ValidationType $type,
        mixed $value,
        string $property,
        object $entity,
    ): void {
        $args = [$type, $value, $property, $entity];
        $reflection = $this->reflectCallable($validator);
        if (null === $reflection) {
            $this->executeValidator($validator, $args);
            return;
        }

        $required = $reflection->getNumberOfRequiredParameters();
        if (!$reflection->isVariadic() && $required > 4) {
            throw new RuntimeException('Validator callable requires too many arguments.');
        }

        if ($reflection->isVariadic()) {
            $this->executeValidator($validator, $args);
            return;
        }

        $count = min($reflection->getNumberOfParameters(), 4);
        $this->executeValidator($validator, array_slice($args, 0, $count));
    }

    /**
     * @param array<int,mixed> $args
     */
    private function executeValidator(callable $validator, array $args): void
    {
        try {
            $validator(...$args);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $property = $args[2] ?? null;
            $value = $args[1] ?? null;
            $type = $args[0] ?? null;
            $type = $type instanceof ValidationType ? $type : null;
            throw new ValidationException(
                $exception->getMessage(),
                is_string($property) ? $property : null,
                $value,
                $type,
                $exception,
            );
        }
    }

    /**
     * @param TEntity $entity
     */
    private function callHook(object $entity, string $method): void
    {
        if (!is_callable([$entity, $method])) {
            return;
        }

        $entity->{$method}();
    }

    /**
     * @param TEntity $entity
     */
    private function dispatchEvent(object $entity, string $name): void
    {
        $this->dispatcher?->dispatch(new EntityEvent($entity, $name), $name);
    }

    /**
     * @param TEntity $entity
     */
    private function extractPrimaryId(object $entity): string
    {
        if (1 !== count($this->metadata->primaryKeys)) {
            return '';
        }

        $primaryKey = $this->metadata->primaryKeys[0];
        $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
        if (!property_exists($entity, $property)) {
            return '';
        }

        $value = $entity->{$property} ?? null;
        if (null === $value) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @param array<int,object> $entities
     * @param array<int,string> $relations
     */
    private function loadRelationsFor(array $entities, array $relations): void
    {
        if (empty($entities) || empty($relations)) {
            return;
        }

        $tree = $this->buildRelationTree($relations);
        if (empty($tree)) {
            return;
        }

        $this->loadRelationTree($entities, $tree);
    }

    /**
     * @param array<int,object> $entities
     * @param array<string,array{options:mixed,children:array}> $tree
     */
    private function loadRelationTree(array $entities, array $tree): void
    {
        foreach ($tree as $relationName => $node) {
            $relation = $this->metadata->relationFor($relationName);
            if (null === $relation) {
                throw new RuntimeException('Unknown relation: ' . $relationName);
            }

            $options = $node['options'] ?? null;
            $children = $node['children'] ?? [];

            $relatedEntities = $this->loadRelation($entities, $relation, $options);
            if (empty($children) || empty($relatedEntities)) {
                continue;
            }

            $relatedRepo = $this->repositoryFor($relation->target);
            $relatedRepo->loadRelationTree($relatedEntities, $children);
        }
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,object>
     */
    private function loadRelation(array $entities, RelationMetadata $relation, mixed $options): array
    {
        $relatedRepo = $this->repositoryFor($relation->target);
        $relatedMeta = $relatedRepo->metadata;

        if (RelationMetadata::TYPE_BELONGS_TO === $relation->type) {
            return $this->loadBelongsTo($entities, $relation, $relatedRepo, $relatedMeta, $options);
        }

        if (RelationMetadata::TYPE_BELONGS_TO_MANY === $relation->type) {
            return $this->loadBelongsToMany($entities, $relation, $relatedRepo, $relatedMeta, $options);
        }

        return $this->loadHasRelation($entities, $relation, $relatedRepo, $relatedMeta, $options);
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,object>
     */
    private function loadBelongsToMany(
        array $entities,
        RelationMetadata $relation,
        EntityRepository $relatedRepo,
        EntityMetadata $relatedMeta,
        mixed $options,
    ): array {
        if ($options instanceof RelationOptions && null !== $options->perParentLimit()) {
            throw new RuntimeException('Per-parent limit is not supported for many-to-many relations.');
        }

        if (null === $relation->pivotTable || null === $relation->foreignPivotKey || null === $relation->relatedPivotKey) {
            throw new RuntimeException('Many-to-many relation is missing pivot configuration.');
        }

        $parentProperty = $this->resolvePropertyName($this->metadata, $relation->localKey);
        $parentKeys = $this->collectKeys($entities, $parentProperty);
        if (empty($parentKeys)) {
            $this->assignEmptyRelation($entities, $relation);
            return [];
        }

        $pivotForeign = $relation->foreignPivotKey;
        $pivotRelated = $relation->relatedPivotKey;
        $relatedKey = $relation->relatedKey ?? 'id';

        $relatedColumn = $this->resolveColumnName($relatedMeta, $relatedKey);
        $pivotColumns = array_values(
            array_unique(
                array_filter(
                    [
                        $pivotForeign,
                        $pivotRelated,
                        ...$relation->pivotColumns,
                    ],
                    static fn(string $value): bool => '' !== trim($value),
                ),
            ),
        );

        $relatedSelect = $this->prefixColumns($relatedMeta, 'r');

        $pivotAliases = [];
        foreach ($pivotColumns as $column) {
            $pivotAliases[$column] = '__pivot_' . $column;
        }

        $query = new SelectQuery($relation->pivotTable, 'p');
        foreach ($relatedSelect as $column) {
            $query->selectAs($column, substr($column, 2));
        }
        foreach ($pivotAliases as $column => $alias) {
            $query->selectAs('p.' . $column, $alias);
        }
        $query->join($relatedMeta->table, 'r', Condition::columnEquals('p.' . $pivotRelated, 'r.' . $relatedColumn));

        $condition = Condition::in('p.' . $pivotForeign, $parentKeys);
        $condition = $this->applyRelationOptions($query, $condition, $options);
        $query->where($condition);

        $rows = $this->connection->fetchAll($query);
        if (empty($rows)) {
            $this->assignEmptyRelation($entities, $relation);
            return [];
        }

        $related = [];
        $grouped = [];
        $pivotForeignAlias = $pivotAliases[$pivotForeign] ?? $pivotForeign;

        foreach ($rows as $row) {
            if (!array_key_exists($pivotForeignAlias, $row)) {
                continue;
            }
            $parentValue = $row[$pivotForeignAlias];
            if (null === $parentValue) {
                continue;
            }

            $entity = $this->hydrateRowWithMetadata($relation->target, $relatedMeta, $row);
            $pivot = new \stdClass();
            foreach ($pivotAliases as $column => $alias) {
                if (!array_key_exists($alias, $row)) {
                    continue;
                }
                $pivot->{$column} = $row[$alias];
            }

            $pivotProperty = '' !== trim($relation->pivotProperty) ? $relation->pivotProperty : 'pivot';
            $entity->{$pivotProperty} = $pivot;

            $grouped[(string) $parentValue][] = $entity;
            $related[] = $entity;
        }

        foreach ($entities as $entity) {
            if (!property_exists($entity, $parentProperty)) {
                continue;
            }
            $value = $entity->{$parentProperty} ?? null;
            if (null === $value) {
                $this->assignEmptyValue($entity, $relation);
                continue;
            }

            $entity->{$relation->name} = $grouped[(string) $value] ?? [];
        }

        return $related;
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,object>
     */
    private function loadBelongsTo(
        array $entities,
        RelationMetadata $relation,
        EntityRepository $relatedRepo,
        EntityMetadata $relatedMeta,
        mixed $options,
    ): array {
        if ($options instanceof RelationOptions && null !== $options->perParentLimit()) {
            throw new RuntimeException('Per-parent limit is not supported for belongs-to relations.');
        }

        $foreignProperty = $this->resolvePropertyName($this->metadata, $relation->foreignKey);
        $keys = $this->collectKeys($entities, $foreignProperty);
        if (empty($keys)) {
            $this->assignEmptyRelation($entities, $relation);
            return [];
        }

        $ownerColumn = $this->resolveColumnName($relatedMeta, $relation->localKey);
        $condition = Condition::in($ownerColumn, $keys);
        $query = $relatedRepo->select();
        $condition = $this->applyRelationOptions($query, $condition, $options);
        $query->where($condition);

        $related = $relatedRepo->fetchAll($query);
        $ownerProperty = $this->resolvePropertyName($relatedMeta, $ownerColumn);

        $lookup = [];
        foreach ($related as $item) {
            if (!property_exists($item, $ownerProperty)) {
                continue;
            }
            $value = $item->{$ownerProperty} ?? null;
            if (null === $value) {
                continue;
            }
            $lookup[(string) $value] = $item;
        }

        foreach ($entities as $entity) {
            if (!property_exists($entity, $foreignProperty)) {
                continue;
            }
            $value = $entity->{$foreignProperty} ?? null;
            if (null === $value) {
                $entity->{$relation->name} = null;
                continue;
            }

            $entity->{$relation->name} = $lookup[(string) $value] ?? null;
        }

        return $related;
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,object>
     */
    private function loadHasRelation(
        array $entities,
        RelationMetadata $relation,
        EntityRepository $relatedRepo,
        EntityMetadata $relatedMeta,
        mixed $options,
    ): array {
        if ($options instanceof RelationOptions && null !== $options->perParentLimit()) {
            return $this->loadHasRelationWithLimit($entities, $relation, $relatedRepo, $relatedMeta, $options);
        }

        $localProperty = $this->resolvePropertyName($this->metadata, $relation->localKey);
        $keys = $this->collectKeys($entities, $localProperty);
        if (empty($keys)) {
            $this->assignEmptyRelation($entities, $relation);
            return [];
        }

        $foreignColumn = $this->resolveColumnName($relatedMeta, $relation->foreignKey);
        $condition = Condition::in($foreignColumn, $keys);
        $query = $relatedRepo->select();
        $condition = $this->applyRelationOptions($query, $condition, $options);
        $query->where($condition);

        $related = $relatedRepo->fetchAll($query);
        $foreignProperty = $this->resolvePropertyName($relatedMeta, $foreignColumn);

        $grouped = [];
        foreach ($related as $item) {
            if (!property_exists($item, $foreignProperty)) {
                continue;
            }
            $value = $item->{$foreignProperty} ?? null;
            if (null === $value) {
                continue;
            }
            $grouped[(string) $value][] = $item;
        }

        foreach ($entities as $entity) {
            if (!property_exists($entity, $localProperty)) {
                continue;
            }
            $value = $entity->{$localProperty} ?? null;
            if (null === $value) {
                $this->assignEmptyValue($entity, $relation);
                continue;
            }

            $items = $grouped[(string) $value] ?? [];
            if ($relation->isToMany()) {
                $entity->{$relation->name} = $items;
                continue;
            }

            $entity->{$relation->name} = $items[0] ?? null;
        }

        return $related;
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,object>
     */
    private function loadHasRelationWithLimit(
        array $entities,
        RelationMetadata $relation,
        EntityRepository $relatedRepo,
        EntityMetadata $relatedMeta,
        RelationOptions $options,
    ): array {
        if ($options->hasPagination()) {
            throw new RuntimeException('Per-parent limit cannot be combined with limit/offset.');
        }

        $limit = $options->perParentLimit();
        if (null === $limit) {
            return [];
        }

        $dialect = $this->connection->dialect();
        if (!$dialect->supportsWindowFunctions()) {
            throw new RuntimeException('Per-parent limit requires window functions for ' . $dialect->name() . '.');
        }

        $localProperty = $this->resolvePropertyName($this->metadata, $relation->localKey);
        $keys = $this->collectKeys($entities, $localProperty);
        if (empty($keys)) {
            $this->assignEmptyRelation($entities, $relation);
            return [];
        }

        $foreignColumn = $this->resolveColumnName($relatedMeta, $relation->foreignKey);
        $condition = Condition::in($foreignColumn, $keys);
        $extra = $options->condition();
        if (null !== $extra) {
            $condition = Condition::and($condition, $extra);
        }

        $orderSql = $this->buildWindowOrderSql($relatedMeta, $options, $dialect);
        $partitionColumn = Identifier::quote($dialect, $foreignColumn);

        $inner = $relatedRepo->select();
        $inner->where($condition);
        $inner->selectRaw(
            'ROW_NUMBER() OVER (PARTITION BY ' . $partitionColumn . ' ORDER BY ' . $orderSql . ') AS __rn',
        );

        $alias = 'rel';
        $outer = new SelectQuery('ignored');
        $outer->fromSubquery($inner, $alias);
        $outer->select($this->prefixColumns($relatedMeta, $alias));
        $outer->where(Condition::lessOrEqual($alias . '.__rn', $limit));
        $outer->orderBy($alias . '.' . $foreignColumn, 'ASC');
        $outer->orderBy($alias . '.__rn', 'ASC');

        $related = $relatedRepo->fetchAll($outer);
        $foreignProperty = $this->resolvePropertyName($relatedMeta, $foreignColumn);

        $grouped = [];
        foreach ($related as $item) {
            if (!property_exists($item, $foreignProperty)) {
                continue;
            }
            $value = $item->{$foreignProperty} ?? null;
            if (null === $value) {
                continue;
            }
            $grouped[(string) $value][] = $item;
        }

        foreach ($entities as $entity) {
            if (!property_exists($entity, $localProperty)) {
                continue;
            }
            $value = $entity->{$localProperty} ?? null;
            if (null === $value) {
                $this->assignEmptyValue($entity, $relation);
                continue;
            }

            $items = $grouped[(string) $value] ?? [];
            if ($relation->isToMany()) {
                $entity->{$relation->name} = $items;
                continue;
            }

            $entity->{$relation->name} = $items[0] ?? null;
        }

        return $related;
    }

    /**
     * @param array<int,object> $entities
     */
    private function assignEmptyRelation(array $entities, RelationMetadata $relation): void
    {
        foreach ($entities as $entity) {
            $this->assignEmptyValue($entity, $relation);
        }
    }

    private function assignEmptyValue(object $entity, RelationMetadata $relation): void
    {
        if ($relation->isToMany()) {
            $entity->{$relation->name} = [];
            return;
        }

        $entity->{$relation->name} = null;
    }

    private function applyRelationOptions(SelectQuery $query, Condition $condition, mixed $options): Condition
    {
        if (null === $options) {
            return $condition;
        }

        if ($options instanceof RelationOptions) {
            $options->apply($query);
            $extra = $options->condition();
            return null === $extra ? $condition : Condition::and($condition, $extra);
        }

        if (is_callable($options)) {
            $result = $options($query, $condition);
            if (null === $result) {
                return $condition;
            }

            if (!$result instanceof Condition) {
                throw new RuntimeException('Relation option callable must return Condition or null.');
            }

            return Condition::and($condition, $result);
        }

        throw new RuntimeException('Relation options must be a callable or RelationOptions.');
    }

    /**
     * @return array<int,string>
     */
    private function prefixColumns(EntityMetadata $metadata, string $alias): array
    {
        $columns = array_values($metadata->columnsByProperty);
        $prefixed = [];
        foreach ($columns as $column) {
            $prefixed[] = $alias . '.' . $column;
        }

        return $prefixed;
    }

    private function buildWindowOrderSql(
        EntityMetadata $metadata,
        RelationOptions $options,
        DialectInterface $dialect,
    ): string {
        $clauses = $options->orderByClauses();
        if (empty($clauses)) {
            throw new RuntimeException('Per-parent limit requires order by columns.');
        }

        $parts = [];
        foreach ($clauses as $order) {
            if ('raw' === $order['type']) {
                $expression = $order['expression'];
            } else {
                $expression = Identifier::quote(
                    $dialect,
                    $this->mapSelectableColumnWithMetadata($metadata, $order['expression']),
                );
            }

            if (null !== $order['direction']) {
                $expression .= ' ' . $order['direction'];
            }

            $parts[] = $expression;
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<int|string,string|RelationOptions|callable|null> $relations
     * @return array<string,array{options:mixed,children:array}>
     */
    private function buildRelationTree(array $relations): array
    {
        $tree = [];
        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                $relation = $value;
                $options = null;
            } else {
                $relation = $key;
                $options = $value;
            }

            $relation = trim((string) $relation);
            if ('' === $relation) {
                continue;
            }

            $parts = array_filter(
                array_map('trim', explode('.', $relation)),
                static fn(string $part) => '' !== $part,
            );
            if (empty($parts)) {
                continue;
            }

            $node = &$tree;
            $lastIndex = count($parts) - 1;
            foreach ($parts as $index => $part) {
                if (!isset($node[$part]) || !is_array($node[$part])) {
                    $node[$part] = ['options' => null, 'children' => []];
                }

                if ($index === $lastIndex && null !== $options) {
                    $node[$part]['options'] = $options;
                }

                $node = &$node[$part]['children'];
            }
            unset($node);
        }

        return $tree;
    }

    /**
     * @param array<int,object> $entities
     * @return array<int,mixed>
     */
    private function collectKeys(array $entities, string $property): array
    {
        $keys = [];
        foreach ($entities as $entity) {
            if (!property_exists($entity, $property)) {
                continue;
            }
            $value = $entity->{$property} ?? null;
            if (null === $value) {
                continue;
            }
            $keys[] = $value;
        }

        if (empty($keys)) {
            return [];
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    private function resolveColumnName(EntityMetadata $metadata, string $key): string
    {
        return $metadata->columnFor($key) ?? $key;
    }

    private function resolvePropertyName(EntityMetadata $metadata, string $key): string
    {
        return $metadata->propertyFor($key) ?? $key;
    }

    private function repositoryFor(string $className): self
    {
        if (!isset($this->repositoryCache[$className])) {
            $this->repositoryCache[$className] = new self($this->connection, $this->metadataFactory, $className);
        }

        return $this->repositoryCache[$className];
    }

    private function applyGeneratedId(object $entity, string $id): void
    {
        if ('' === $id) {
            return;
        }

        if (1 !== count($this->metadata->primaryKeys)) {
            return;
        }

        $primaryKey = $this->metadata->primaryKeys[0];
        if (!in_array($primaryKey, $this->metadata->autoIncrementKeys, true)) {
            return;
        }

        $property = $this->metadata->propertyFor($primaryKey) ?? $primaryKey;
        if (property_exists($entity, $property)) {
            $entity->{$property} = is_numeric($id) ? (int) $id : $id;
        }
    }

    private function applyScopes(SelectQuery $query): void
    {
        $this->applyGlobalScopes($query);
        $this->applyLocalScopes($query);
    }

    private function applyGlobalScopes(SelectQuery $query): void
    {
        if (!$this->metadata->isSoftDelete()) {
            return;
        }

        $column = (string) $this->metadata->softDeleteColumn;
        $condition = $this->onlyTrashed
            ? Condition::isNotNull($column)
            : Condition::isNull($column);

        if ($this->includeTrashed) {
            if ($this->onlyTrashed) {
                $query->where($condition);
            }
            return;
        }

        $query->where($condition);
    }

    private function applyLocalScopes(SelectQuery $query): void
    {
        foreach ($this->localScopes as $scope) {
            $scope($this, $query);
        }
    }
}
