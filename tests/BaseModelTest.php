<?php

declare(strict_types=1);

namespace tests;

use DateTimeImmutable;
use tests\fixtures\DifferEntity;
use tests\fixtures\IgnoredModelEntity;
use tests\fixtures\ProtectedModelEntity;
use tests\fixtures\StringableEntity;
use tests\fixtures\StringableValue;

final class BaseModelTest extends TestCase
{
    public function testDiffSkipsIgnored(): void
    {
        $entity = IgnoredModelEntity::fromRow([
            'id' => 1,
            'name' => 'Before',
            'secret' => 'before-secret',
        ]);

        $entity->secret = 'after-secret';

        static::assertSame([], $entity->diff());
    }

    public function testApplySkipsIgnored(): void
    {
        $target = IgnoredModelEntity::fromRow([
            'id' => 1,
            'name' => 'Before',
            'secret' => 'before-secret',
        ]);
        $source = IgnoredModelEntity::fromRow([
            'id' => 2,
            'name' => 'After',
            'secret' => 'after-secret',
        ]);

        $target->apply($source);

        static::assertSame(1, $target->id);
        static::assertSame('After', $target->name);
        static::assertSame('before-secret', $target->secret);
    }

    public function testApplySupportsColumn(): void
    {
        $target = IgnoredModelEntity::fromRow([
            'id' => 1,
            'name' => 'Before',
            'secret' => 'before-secret',
        ]);
        $source = IgnoredModelEntity::fromRow([
            'id' => 1,
            'name' => 'After',
            'secret' => 'after-secret',
        ]);

        $target->apply($source, ['id']);

        static::assertSame(1, $target->id);
        static::assertSame('Before', $target->name);
        static::assertSame('before-secret', $target->secret);
    }

    public function testDiffSupportsColumn(): void
    {
        $entity = IgnoredModelEntity::fromRow([
            'id' => 1,
            'name' => 'Before',
            'secret' => 'before-secret',
        ]);

        $entity->id = 9;
        $entity->name = 'After';

        static::assertSame(['id' => 9], $entity->diff(columns: ['id']));
    }

    public function testDiffTreatsEqual(): void
    {
        $entity = StringableEntity::fromRow([
            'id' => 1,
            'created' => new DateTimeImmutable('2024-01-01 00:00:00+00:00'),
            'name' => 'alpha',
        ]);

        $entity->created = new DateTimeImmutable('2024-01-01 01:00:00+01:00');

        static::assertSame([], $entity->diff(columns: ['created']));
    }

    public function testDiffTreatsStrings(): void
    {
        $entity = StringableEntity::fromRow([
            'id' => 1,
            'created' => new DateTimeImmutable('2024-01-01 00:00:00+00:00'),
            'name' => new StringableValue('alpha'),
        ]);

        $entity->name = new StringableValue('alpha');

        static::assertSame([], $entity->diff(columns: ['name']));
    }

    public function testDiffSupportsArray(): void
    {
        $entity = DifferEntity::fromRow([
            'id' => 1,
            'title' => 'Alpha',
            'slug' => 'Dash',
        ]);

        $entity->title = ' Alpha ';

        static::assertSame([], $entity->diff(columns: ['title']));
    }

    public function testDiffSupportsString(): void
    {
        $entity = DifferEntity::fromRow([
            'id' => 1,
            'title' => 'Alpha',
            'slug' => 'Dash',
        ]);

        $entity->slug = 'dAsH';

        static::assertSame([], $entity->diff(columns: ['slug']));
    }

    public function testToArrayOmits(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);

        static::assertSame(
            [
                'id' => 1,
                'name' => 'Alpha',
            ],
            $entity->toArray(),
        );
    }

    public function testToArrayIncludes(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);

        static::assertSame(
            [
                'id' => 1,
                'name' => 'Alpha',
                'secret' => ['token' => 'abc123'],
            ],
            $entity->toArray(omit: false),
        );
    }

    public function testToArrayEncode(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);

        static::assertSame(
            [
                'id' => 1,
                'name' => 'Alpha',
            ],
            $entity->toArray(encode: true),
        );
    }

    public function testToArrayProtected(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);

        static::assertSame(
            [
                'id' => 1,
                'name' => 'Alpha',
                'secret' => '{"token":"abc123"}',
            ],
            $entity->toArray(encode: true, omit: false),
        );
    }

    public function testJsonSerializeOmits(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);
        $entity->transient = 'visible';

        static::assertSame(
            '{"id":1,"name":"Alpha"}',
            json_encode($entity, JSON_THROW_ON_ERROR),
        );
    }

    public function testDiffTracksProtected(): void
    {
        $entity = ProtectedModelEntity::fromRow([
            'id' => 1,
            'name' => 'Alpha',
            'secret' => ['token' => 'abc123'],
        ]);

        $entity->secret = ['token' => 'xyz789'];

        static::assertSame(
            [
                'secret' => ['token' => 'xyz789'],
            ],
            $entity->diff(),
        );
    }
}
