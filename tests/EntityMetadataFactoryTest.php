<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Orm\EntityMetadataFactory;
use arabcoders\database\Orm\RelationMetadata;
use arabcoders\database\Transformer\TransformType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\fixtures\AutoNullableTransformEntity;
use tests\fixtures\BlogPostEntity;
use tests\fixtures\BlogProfileEntity;
use tests\fixtures\BlogTagEntity;
use tests\fixtures\BlogUserEntity;
use tests\fixtures\InvalidValidationTypeEntity;
use tests\fixtures\OnCreateUpdateEntity;
use tests\fixtures\PhaseValidatedEntity;
use tests\fixtures\NoColumnEntity;
use tests\fixtures\NoTableEntity;
use tests\fixtures\SoftDeleteUserEntity;
use tests\fixtures\UserEntity;
use tests\fixtures\ValidatedUserEntity;
use tests\fixtures\ValidatedProfileEntity;

final class EntityMetadataFactoryTest extends TestCase
{
    public function testMetadataFactoryBuildsMappings(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(UserEntity::class);

        static::assertSame('users', $meta->table);
        static::assertSame(
            [
                'id' => 'id',
                'email' => 'email',
                'displayName' => 'display_name',
            ],
            $meta->columnsByProperty,
        );
        static::assertSame(['id'], $meta->primaryKeys);
        static::assertSame(['id'], $meta->autoIncrementKeys);
    }

    public function testMetadataFactoryRegistersColumnHooks(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(OnCreateUpdateEntity::class);

        static::assertSame(
            [
                'uuid' => ['create' => 'tests\\fixtures\\OnCreateUpdateEntity::makeUuid'],
                'createdAt' => ['create' => 'tests\\fixtures\\OnCreateUpdateEntity::stampTime'],
                'updatedAt' => ['update' => 'tests\\fixtures\\OnCreateUpdateEntity::stampTime'],
            ],
            $meta->hooksByProperty,
        );
    }

    public function testMetadataFactoryRegistersValidators(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(ValidatedUserEntity::class);

        static::assertArrayHasKey('username', $meta->validatorsByProperty);
        static::assertCount(1, $meta->validatorsByProperty['username']);
        static::assertIsCallable($meta->validatorsByProperty['username'][0]['callable']);
    }

    public function testMetadataFactoryRegistersMultipleValidators(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(ValidatedProfileEntity::class);

        static::assertArrayHasKey('username', $meta->validatorsByProperty);
        static::assertCount(3, $meta->validatorsByProperty['username']);
    }

    public function testMetadataFactoryRegistersPhaseAwareValidators(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(PhaseValidatedEntity::class);

        static::assertArrayHasKey('username', $meta->validatorsByProperty);
        static::assertCount(2, $meta->validatorsByProperty['username']);
        static::assertIsCallable($meta->validatorsByProperty['username'][0]['callable']);
        static::assertContains(\arabcoders\database\Validator\ValidationType::CREATE, $meta->validatorsByProperty['username'][0]['types']);
    }

    public function testMetadataFactoryRejectsInvalidValidatorTypeArray(): void
    {
        $factory = new EntityMetadataFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Validate type array must contain only ValidationType values.');
        $factory->fromClass(InvalidValidationTypeEntity::class);
    }

    public function testMetadataFactoryInfersNullableTransformArgument(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(AutoNullableTransformEntity::class);

        $transform = $meta->transformFor('tags');
        static::assertIsCallable($transform);
        static::assertNull($transform(TransformType::ENCODE, null));
    }

    public function testMetadataFactoryRequiresTableAttribute(): void
    {
        $factory = new EntityMetadataFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Entity must define a Table attribute');
        $factory->fromClass(NoTableEntity::class);
    }

    public function testMetadataFactoryRequiresColumnMappings(): void
    {
        $factory = new EntityMetadataFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Entity has no column mappings');
        $factory->fromClass(NoColumnEntity::class);
    }

    public function testMetadataFactoryBuildsRelations(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(BlogUserEntity::class);

        $posts = $meta->relationFor('posts');
        static::assertNotNull($posts);
        static::assertSame(RelationMetadata::TYPE_HAS_MANY, $posts->type);
        static::assertSame(BlogPostEntity::class, $posts->target);
        static::assertSame('user_id', $posts->foreignKey);
        static::assertSame('id', $posts->localKey);

        $profile = $meta->relationFor('profile');
        static::assertNotNull($profile);
        static::assertSame(RelationMetadata::TYPE_HAS_ONE, $profile->type);
        static::assertSame(BlogProfileEntity::class, $profile->target);
        static::assertSame('user_id', $profile->foreignKey);
        static::assertSame('id', $profile->localKey);

        $tags = $meta->relationFor('tags');
        static::assertNotNull($tags);
        static::assertSame(RelationMetadata::TYPE_BELONGS_TO_MANY, $tags->type);
        static::assertSame(BlogTagEntity::class, $tags->target);
        static::assertSame('user_tags', $tags->pivotTable);
        static::assertSame('user_id', $tags->foreignPivotKey);
        static::assertSame('tag_id', $tags->relatedPivotKey);
        static::assertSame(['tagged_at'], $tags->pivotColumns);

        $postMeta = $factory->fromClass(BlogPostEntity::class);
        $belongsTo = $postMeta->relationFor('user');
        static::assertNotNull($belongsTo);
        static::assertSame(RelationMetadata::TYPE_BELONGS_TO, $belongsTo->type);
        static::assertSame(BlogUserEntity::class, $belongsTo->target);
        static::assertSame('userId', $belongsTo->foreignKey);
        static::assertSame('id', $belongsTo->localKey);
    }

    public function testMetadataFactoryRegistersSoftDelete(): void
    {
        $factory = new EntityMetadataFactory();
        $meta = $factory->fromClass(SoftDeleteUserEntity::class);

        static::assertTrue($meta->isSoftDelete());
        static::assertSame('deleted_at', $meta->softDeleteColumn);
    }
}
