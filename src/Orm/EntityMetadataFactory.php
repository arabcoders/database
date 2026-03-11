<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Attributes\Orm\BelongsTo;
use arabcoders\database\Attributes\Orm\BelongsToMany;
use arabcoders\database\Attributes\Orm\HasMany;
use arabcoders\database\Attributes\Orm\HasOne;
use arabcoders\database\Attributes\Orm\SoftDelete;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Transformer\Transform;
use arabcoders\database\Validator\Validate;
use arabcoders\database\Validator\ValidationType;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class EntityMetadataFactory
{
    /**
     * Build ORM metadata for an entity class by reading schema, relation, transform, and validation attributes.
     *
     * @param string $className Entity class name.
     * @return EntityMetadata
     * @throws RuntimeException If required Table/Column attributes are missing or relation attributes conflict.
     */
    public function fromClass(string $className): EntityMetadata
    {
        $reflection = new ReflectionClass($className);
        $tableAttr = $this->resolveTable($reflection);
        $tableName = $tableAttr->name ?? $reflection->getShortName();

        $columnsByProperty = [];
        $propertiesByColumn = [];
        $primaryKeys = [];
        $autoIncrementKeys = [];
        $relations = [];
        $transformsByProperty = [];
        $validatorsByProperty = [];
        $hooksByProperty = [];
        $softDeleteColumn = null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $relation = $this->resolveRelation($property);
            if (null !== $relation) {
                $relations[$property->getName()] = $relation;
                continue;
            }

            $columnAttr = $this->resolveColumn($property);
            if (null === $columnAttr) {
                continue;
            }

            $transform = $this->resolveTransform($property);
            if (null !== $transform) {
                $transformsByProperty[$property->getName()] = $transform;
            }

            $validators = $this->resolveValidators($property);
            if (!empty($validators)) {
                $validatorsByProperty[$property->getName()] = $validators;
            }

            $columnName = $columnAttr->name ?? $property->getName();
            $columnsByProperty[$property->getName()] = $columnName;
            $propertiesByColumn[$columnName] = $property->getName();

            if ($columnAttr->primary) {
                $primaryKeys[] = $columnName;
                if ($columnAttr->autoIncrement) {
                    $autoIncrementKeys[] = $columnName;
                }
            }

            if (!empty($columnAttr->hooks)) {
                $hooksByProperty[$property->getName()] = $this->normalizeHooks($columnAttr->hooks);
            }
        }

        if (empty($columnsByProperty)) {
            throw new RuntimeException('Entity has no column mappings: ' . $className);
        }

        $softDelete = $this->resolveSoftDelete($reflection);
        if (null !== $softDelete) {
            $softDeleteColumn = $softDelete;
        }

        return new EntityMetadata(
            className: $className,
            table: $tableName,
            columnsByProperty: $columnsByProperty,
            propertiesByColumn: $propertiesByColumn,
            primaryKeys: $primaryKeys,
            autoIncrementKeys: $autoIncrementKeys,
            relations: $relations,
            transformsByProperty: $transformsByProperty,
            validatorsByProperty: $validatorsByProperty,
            hooksByProperty: $hooksByProperty,
            softDeleteColumn: $softDeleteColumn,
        );
    }

    /**
     * @param array<string,mixed> $hooks
     * @return array<string,string>
     */
    private function normalizeHooks(array $hooks): array
    {
        $normalized = [];
        foreach ($hooks as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $event = trim($key);
            if ('' === $event) {
                continue;
            }

            $callable = null;
            if (is_string($value)) {
                $callable = trim($value);
            } elseif (is_array($value)) {
                $class = $value[0] ?? null;
                $method = $value[1] ?? null;
                if (is_object($class)) {
                    $class = $class::class;
                }
                if (is_string($class) && is_string($method)) {
                    $class = trim($class);
                    $method = trim($method);
                    if ('' !== $class && '' !== $method) {
                        $callable = $class . '::' . $method;
                    }
                }
            }

            if (null === $callable || '' === $callable) {
                continue;
            }

            $normalized[$event] = $callable;
        }

        return $normalized;
    }

    private function resolveTable(ReflectionClass $class): Table
    {
        $attributes = $class->getAttributes(Table::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            throw new RuntimeException('Entity must define a Table attribute: ' . $class->getName());
        }

        return $attributes[0]->newInstance();
    }

    private function resolveColumn(ReflectionProperty $property): ?Column
    {
        $attributes = $property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private function resolveRelation(ReflectionProperty $property): ?RelationMetadata
    {
        $belongsTo = $property->getAttributes(BelongsTo::class, ReflectionAttribute::IS_INSTANCEOF);
        $belongsToMany = $property->getAttributes(BelongsToMany::class, ReflectionAttribute::IS_INSTANCEOF);
        $hasOne = $property->getAttributes(HasOne::class, ReflectionAttribute::IS_INSTANCEOF);
        $hasMany = $property->getAttributes(HasMany::class, ReflectionAttribute::IS_INSTANCEOF);

        $total = count($belongsTo) + count($belongsToMany) + count($hasOne) + count($hasMany);
        if (0 === $total) {
            return null;
        }

        if ($total > 1) {
            throw new RuntimeException('Relation attributes are mutually exclusive: ' . $property->getName());
        }

        if (!empty($belongsTo)) {
            $attr = $belongsTo[0]->newInstance();
            return new RelationMetadata(
                name: $property->getName(),
                type: RelationMetadata::TYPE_BELONGS_TO,
                target: $attr->target,
                foreignKey: $attr->foreignKey,
                localKey: $attr->ownerKey,
            );
        }

        if (!empty($belongsToMany)) {
            $attr = $belongsToMany[0]->newInstance();
            return new RelationMetadata(
                name: $property->getName(),
                type: RelationMetadata::TYPE_BELONGS_TO_MANY,
                target: $attr->target,
                foreignKey: $attr->foreignPivotKey,
                localKey: $attr->parentKey,
                pivotTable: $attr->pivotTable,
                foreignPivotKey: $attr->foreignPivotKey,
                relatedPivotKey: $attr->relatedPivotKey,
                relatedKey: $attr->relatedKey,
                pivotColumns: $attr->pivotColumns,
                pivotProperty: $attr->pivotProperty,
            );
        }

        if (!empty($hasOne)) {
            $attr = $hasOne[0]->newInstance();
            return new RelationMetadata(
                name: $property->getName(),
                type: RelationMetadata::TYPE_HAS_ONE,
                target: $attr->target,
                foreignKey: $attr->foreignKey,
                localKey: $attr->localKey,
            );
        }

        $attr = $hasMany[0]->newInstance();
        return new RelationMetadata(
            name: $property->getName(),
            type: RelationMetadata::TYPE_HAS_MANY,
            target: $attr->target,
            foreignKey: $attr->foreignKey,
            localKey: $attr->localKey,
        );
    }

    private function resolveTransform(ReflectionProperty $property): ?callable
    {
        $attributes = $property->getAttributes(Transform::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return null;
        }

        $transform = $attributes[0]->newInstance();
        return $transform->makeCallable($property);
    }

    /**
     * @return array<int,array{types:array<int,ValidationType>,callable:callable}>
     */
    private function resolveValidators(ReflectionProperty $property): array
    {
        $attributes = $property->getAttributes(Validate::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return [];
        }

        $validators = [];
        foreach ($attributes as $attribute) {
            $validator = $attribute->newInstance();
            $validators[] = [
                'types' => $validator->resolvedTypes(),
                'callable' => $validator->makeCallable(),
            ];
        }

        return $validators;
    }

    private function resolveSoftDelete(ReflectionClass $class): ?string
    {
        $attributes = $class->getAttributes(SoftDelete::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attributes)) {
            return null;
        }

        $softDelete = $attributes[0]->newInstance();
        $column = trim($softDelete->column);
        if ('' === $column) {
            throw new RuntimeException('Soft delete column is required.');
        }

        return $column;
    }
}
