<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Model\PreservesDirtyStateOnHydrate;
use arabcoders\database\Model\TracksChanges;
use arabcoders\database\Transformer\TransformType;
use RuntimeException;

final class EntityHydrator
{
    /**
     * @param array<string,mixed> $row
     */
    public function hydrate(string $className, EntityMetadata $metadata, array $row): object
    {
        $entity = new $className();
        if (!is_object($entity)) {
            throw new RuntimeException('Unable to hydrate entity: ' . $className);
        }

        return $this->hydrateInto($entity, $metadata, $row);
    }

    /**
     * @param array<string,mixed> $row
     */
    public function hydrateInto(object $entity, EntityMetadata $metadata, array $row): object
    {
        $preserveDirty = $entity instanceof PreservesDirtyStateOnHydrate && $entity->preserveDirtyOnHydrate();
        $dirtyFields = $preserveDirty ? array_fill_keys($entity->dirtyFields(), true) : [];
        $refreshedFields = [];

        foreach ($row as $column => $value) {
            $property = $metadata->propertyFor($column) ?? $column;
            if (property_exists($entity, $property)) {
                if ($preserveDirty && isset($dirtyFields[$property])) {
                    continue;
                }

                $transform = $metadata->transformFor($property);
                if (null !== $transform) {
                    $value = $transform(TransformType::DECODE, $value);
                }

                $entity->{$property} = $value;
                $refreshedFields[$property] = true;
            }
        }

        if ($preserveDirty) {
            $entity->markCleanFields(array_keys($refreshedFields));
        } elseif ($entity instanceof TracksChanges) {
            $entity->markClean();
        }

        return $entity;
    }
}
