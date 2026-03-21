<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Orm\EntityHydrator;
use arabcoders\database\Orm\EntityMetadataFactory;
use PHPUnit\Framework\TestCase;
use tests\fixtures\AutoNullableTransformEntity;
use tests\fixtures\DirtyAwareUserEntity;
use tests\fixtures\ProtectedModelEntity;
use tests\fixtures\TransformedNoteEntity;
use tests\fixtures\UserEntity;

final class EntityHydratorTest extends TestCase
{
    public function testHydratorMapsColumnsToProperties(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(UserEntity::class);
        $hydrator = new EntityHydrator();

        $entity = $hydrator->hydrate(UserEntity::class, $metadata, [
            'id' => 12,
            'email' => 'hydrate@example.com',
            'display_name' => 'Hydrated',
            'extra' => 'ignored',
        ]);

        static::assertInstanceOf(UserEntity::class, $entity);
        static::assertSame(12, $entity->id);
        static::assertSame('hydrate@example.com', $entity->email);
        static::assertSame('Hydrated', $entity->displayName);
        static::assertFalse(property_exists($entity, 'extra'));
    }

    public function testHydratorAppliesTransforms(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(TransformedNoteEntity::class);
        $hydrator = new EntityHydrator();

        $entity = $hydrator->hydrate(TransformedNoteEntity::class, $metadata, [
            'id' => 1,
            'tags' => '["alpha","beta"]',
            'created' => '2024-01-01 12:00:00',
        ]);

        static::assertSame(['alpha', 'beta'], $entity->tags);
        static::assertInstanceOf(\DateTimeInterface::class, $entity->created);
    }

    public function testHydratorSupportsNullForNullableTransformedProperty(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(AutoNullableTransformEntity::class);
        $hydrator = new EntityHydrator();

        $entity = $hydrator->hydrate(AutoNullableTransformEntity::class, $metadata, [
            'id' => 1,
            'tags' => null,
        ]);

        static::assertNull($entity->tags);
    }

    public function testHydratorHydratesFreshDirtyAwareEntity(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(DirtyAwareUserEntity::class);
        $hydrator = new EntityHydrator();

        $entity = $hydrator->hydrate(DirtyAwareUserEntity::class, $metadata, [
            'id' => 12,
            'email' => 'hydrate@example.com',
            'display_name' => 'Hydrated',
        ]);

        static::assertInstanceOf(DirtyAwareUserEntity::class, $entity);
        static::assertSame(12, $entity->id);
        static::assertSame('hydrate@example.com', $entity->email);
        static::assertSame('Hydrated', $entity->displayName);
        static::assertSame([], $entity->diff());
    }

    public function testHydratorKeepsFreshDirtyAwareEntityCleanAfterPartialHydrate(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(DirtyAwareUserEntity::class);
        $hydrator = new EntityHydrator();

        $entity = new DirtyAwareUserEntity();

        $hydrator->hydrateInto($entity, $metadata, [
            'id' => 12,
            'email' => 'hydrate@example.com',
        ]);

        static::assertSame(12, $entity->id);
        static::assertSame('hydrate@example.com', $entity->email);
        static::assertSame('', $entity->displayName);
        static::assertSame([], $entity->diff());
    }

    public function testHydratorPreservesDirtyFieldsWhenEntityOptsIn(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(DirtyAwareUserEntity::class);
        $hydrator = new EntityHydrator();

        $entity = DirtyAwareUserEntity::fromRow([
            'id' => 1,
            'email' => 'original@example.com',
            'displayName' => 'Original',
        ]);
        $entity->displayName = 'Dirty';

        $hydrator->hydrateInto($entity, $metadata, [
            'id' => 1,
            'email' => 'fresh@example.com',
            'display_name' => 'Original',
        ]);

        static::assertSame('fresh@example.com', $entity->email);
        static::assertSame('Dirty', $entity->displayName);
        static::assertSame(['displayName' => 'Dirty'], $entity->diff());
    }

    public function testHydratorKeepsLegacyOverwriteBehaviorByDefault(): void
    {
        $factory = new EntityMetadataFactory();
        $metadata = $factory->fromClass(ProtectedModelEntity::class);
        $hydrator = new EntityHydrator();

        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Original',
            'secret' => ['token' => 'abc123'],
        ]);
        $entity->name = 'Dirty';

        $hydrator->hydrateInto($entity, $metadata, [
            'id' => 1,
            'name' => 'Original',
            'secret' => '{"token":"abc123"}',
        ]);

        static::assertSame('Original', $entity->name);
        static::assertSame([], $entity->diff());
    }
}
