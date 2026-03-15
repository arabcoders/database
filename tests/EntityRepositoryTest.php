<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Orm\EntityMetadataFactory;
use arabcoders\database\Orm\EntityRepository;
use arabcoders\database\Orm\OrmManager;
use arabcoders\database\Orm\RelationOptions;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;
use arabcoders\database\Schema\SchemaGenerator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\fixtures\BlogPostEntity;
use tests\fixtures\BlogProfileEntity;
use tests\fixtures\BlogTagEntity;
use tests\fixtures\BlogUserEntity;
use tests\fixtures\DiffUserEntity;
use tests\fixtures\MisconfiguredRelationUserEntity;
use tests\fixtures\MultiKeyEntity;
use tests\fixtures\NoPrimaryEntity;
use tests\fixtures\NullableUserEntity;
use tests\fixtures\OnCreateUpdateEntity;
use tests\fixtures\PhaseValidatedEntity;
use tests\fixtures\ProtectedModelEntity;
use tests\fixtures\SoftDeleteUserEntity;
use tests\fixtures\UserEntity;
use tests\fixtures\ValidatedProfileEntity;
use tests\fixtures\ValidatedUserEntity;

final class EntityRepositoryTest extends TestCase
{
    public function testRepositoryInsertFindUpdateDelete(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $user = new UserEntity();
        $user->email = 'first@example.com';
        $user->displayName = 'First';

        $id = $repo->insert($user);
        static::assertSame((int) $id, $user->id);

        $found = $repo->find($user->id);
        static::assertInstanceOf(UserEntity::class, $found);
        static::assertSame('first@example.com', $found->email);
        static::assertSame('First', $found->displayName);

        $found->displayName = 'Updated';
        $repo->save($found);

        $updated = $repo->find($user->id);
        static::assertSame('Updated', $updated->displayName);

        $matches = $repo->findBy(['email' => 'first@example.com']);
        static::assertCount(1, $matches);

        $displayMatches = $repo->findBy(['displayName' => 'Updated']);
        static::assertCount(1, $displayMatches);

        $repo->delete($updated);
        static::assertNull($repo->find($user->id));

        $list = $repo->findBy();
        static::assertCount(0, $list);
    }

    public function testRepositorySaveInsertsWhenPrimaryKeyIsNull(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [NullableUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, NullableUserEntity::class);

        $user = new NullableUserEntity();
        $user->id = null;
        $user->email = 'insert@example.com';
        $user->displayName = 'Inserted';

        $saved = $repo->save($user);

        static::assertSame(1, $saved);
        static::assertNotNull($user->id);

        $found = $repo->find((int) $user->id);
        static::assertInstanceOf(NullableUserEntity::class, $found);
        static::assertSame('insert@example.com', $found->email);
        static::assertSame('Inserted', $found->displayName);
    }

    public function testRepositorySaveWithMissingPrimaryKeyRowReturnsZero(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [NullableUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, NullableUserEntity::class);

        $user = new NullableUserEntity();
        $user->id = 999;
        $user->email = 'missing@example.com';
        $user->displayName = 'Missing';

        $saved = $repo->save($user);

        static::assertSame(0, $saved);
        static::assertSame(0, $repo->count());
    }

    public function testRepositoryAppliesOnCreateAndOnUpdateHooks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [OnCreateUpdateEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, OnCreateUpdateEntity::class);

        $entity = new OnCreateUpdateEntity();
        $entity->email = 'first@example.com';

        $repo->insert($entity);
        static::assertSame('uuid-1234', $entity->uuid);
        static::assertSame('2024-01-01 00:00:00', $entity->createdAt);
        static::assertSame('', $entity->updatedAt);

        $entity->email = 'updated@example.com';
        $repo->save($entity);

        $updated = $repo->find($entity->id);
        static::assertInstanceOf(OnCreateUpdateEntity::class, $updated);
        static::assertSame('2024-01-02 00:00:00', $updated->updatedAt);
        static::assertSame('uuid-1234', $updated->uuid);
    }

    public function testRepositoryUpdateUsesDiffWhenAvailable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [DiffUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, DiffUserEntity::class);

        $user = new DiffUserEntity();
        $user->email = 'first@example.com';
        $user->displayName = 'First';
        $repo->insert($user);

        $user->email = 'changed@example.com';
        $user->displayName = 'Changed';

        $updated = $repo->save($user);
        static::assertSame(1, $updated);

        $freshRepo = new EntityRepository($connection, $factory, DiffUserEntity::class);
        $fresh = $freshRepo->find($user->id);

        static::assertInstanceOf(DiffUserEntity::class, $fresh);
        static::assertSame('first@example.com', $fresh->email);
        static::assertSame('Changed', $fresh->displayName);
    }

    public function testRepositoryCrudWithBaseModelProtectedFields(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [ProtectedModelEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, ProtectedModelEntity::class);

        $entity = new ProtectedModelEntity();
        $entity->id = 1;
        $entity->name = 'Alpha';
        $entity->secret = ['token' => 'abc123'];

        static::assertEquals(1, $repo->insert($entity));

        $found = $repo->find(1);
        static::assertInstanceOf(ProtectedModelEntity::class, $found);
        static::assertSame('Alpha', $found->name);
        static::assertSame(['token' => 'abc123'], $found->secret);
        static::assertSame(
            [
                'id' => 1,
                'name' => 'Alpha',
            ],
            $found->toArray(),
        );

        $found->name = 'Updated';
        $found->secret = ['token' => 'xyz789'];

        static::assertSame(1, $repo->save($found));

        $updated = $repo->find(1);
        static::assertInstanceOf(ProtectedModelEntity::class, $updated);
        static::assertSame('Updated', $updated->name);
        static::assertSame(['token' => 'xyz789'], $updated->secret);

        static::assertSame(1, $repo->delete($updated));
        static::assertNull($repo->find(1));
    }

    public function testRepositorySupportsWhereCountAndExists(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $first = new UserEntity();
        $first->email = 'first@example.com';
        $first->displayName = 'First';
        $repo->insert($first);

        $second = new UserEntity();
        $second->email = 'second@example.com';
        $second->displayName = 'Second';
        $repo->insert($second);

        $found = $repo->findOneBy(['email' => 'second@example.com']);
        static::assertInstanceOf(UserEntity::class, $found);
        static::assertSame('Second', $found->displayName);

        $foundWhere = $repo->findOneWhere(Condition::equals('email', 'first@example.com'));
        static::assertInstanceOf(UserEntity::class, $foundWhere);
        static::assertSame('First', $foundWhere->displayName);

        $matches = $repo->findWhere(Condition::equals('display_name', 'Second'));
        static::assertCount(1, $matches);

        static::assertSame(2, $repo->count());
        static::assertSame(1, $repo->count(['email' => 'second@example.com']));

        static::assertTrue($repo->exists(['email' => 'first@example.com']));
        static::assertFalse($repo->exists(['email' => 'missing@example.com']));

        static::assertSame(1, $repo->countWhere(Condition::equals('email', 'first@example.com')));
        static::assertTrue($repo->existsWhere(Condition::equals('email', 'first@example.com')));
        static::assertFalse($repo->existsWhere(Condition::equals('email', 'missing@example.com')));

        $updated = $repo->updateBy(['displayName' => 'Updated'], ['email' => 'second@example.com']);
        static::assertSame(1, $updated);

        $updatedEntity = $repo->findOneBy(['email' => 'second@example.com']);
        static::assertSame('Updated', $updatedEntity->displayName);

        $updatedWhere = $repo->updateWhere(['display_name' => 'FirstUpdated'], Condition::equals('email', 'first@example.com'));
        static::assertSame(1, $updatedWhere);

        $updatedFirst = $repo->findOneBy(['email' => 'first@example.com']);
        static::assertSame('FirstUpdated', $updatedFirst->displayName);

        $deletedBy = $repo->deleteBy(['email' => 'second@example.com']);
        static::assertSame(1, $deletedBy);

        $deletedWhere = $repo->deleteWhere(Condition::equals('email', 'first@example.com'));
        static::assertSame(1, $deletedWhere);

        static::assertSame(0, $repo->count());
    }

    public function testRepositoryAppliesValidators(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [ValidatedUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, ValidatedUserEntity::class);

        $entity = new ValidatedUserEntity();
        $entity->username = 'has space';

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('Value must not contain spaces.');
        $repo->insert($entity);
    }

    public function testRepositoryAppliesDatabaseValidatorRules(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [ValidatedUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, ValidatedUserEntity::class);
        $entity = new ValidatedUserEntity();
        $entity->username = 'has space';

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('Value must not contain spaces.');
        $repo->insert($entity);
    }

    public function testRepositoryAppliesMultipleValidators(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [ValidatedProfileEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, ValidatedProfileEntity::class);

        $entity = new ValidatedProfileEntity();
        $entity->username = 'ab';

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('The value must be between 3 and 12');
        $repo->insert($entity);
    }

    public function testFindRequiresSinglePrimaryKey(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, MultiKeyEntity::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Find requires a single primary key.');
        $repo->find(1);
    }

    public function testUpdateRequiresPrimaryKey(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, NoPrimaryEntity::class);
        $entity = new NoPrimaryEntity();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Update requires a primary key.');
        $repo->save($entity);
    }

    public function testDeleteRequiresPrimaryKey(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, NoPrimaryEntity::class);
        $entity = new NoPrimaryEntity();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delete requires a primary key.');
        $repo->delete($entity);
    }

    public function testRepositoryEagerLoadsRelations(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [
            BlogUserEntity::class,
            BlogPostEntity::class,
            BlogProfileEntity::class,
            BlogTagEntity::class,
        ]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $pdo->exec("INSERT INTO users (email) VALUES ('first@example.com')");
        $pdo->exec("INSERT INTO users (email) VALUES ('second@example.com')");

        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'First Post')");
        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Second Post')");
        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (2, 'Third Post')");

        $pdo->exec("INSERT INTO profiles (user_id, display_name) VALUES (1, 'First User')");

        $pdo->exec("INSERT INTO tags (name, user_id) VALUES ('alpha', NULL)");
        $pdo->exec("INSERT INTO tags (name, user_id) VALUES ('beta', NULL)");
        $pdo->exec("INSERT INTO user_tags (user_id, tag_id, tagged_at) VALUES (1, 1, '2024-01-01')");
        $pdo->exec("INSERT INTO user_tags (user_id, tag_id, tagged_at) VALUES (1, 2, '2024-01-02')");
        $pdo->exec("INSERT INTO user_tags (user_id, tag_id, tagged_at) VALUES (2, 2, '2024-01-03')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();

        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $users = $userRepo->findBy([], null, null, [
            'posts' => new RelationOptions()
                ->orderBy('title', 'ASC')
                ->limitPerParent(1),
            'posts.user',
            'tags',
            'profile' => new RelationOptions()->where(Condition::equals('display_name', 'First User')),
        ]);

        static::assertCount(2, $users);
        usort($users, static fn($left, $right) => $left->id <=> $right->id);

        $first = $users[0];
        static::assertInstanceOf(BlogProfileEntity::class, $first->profile);
        static::assertSame('First User', $first->profile->displayName);
        static::assertCount(1, $first->posts);
        static::assertSame('First Post', $first->posts[0]->title);
        static::assertCount(2, $first->tags);
        static::assertSame('alpha', $first->tags[0]->name);
        static::assertSame('2024-01-01', $first->tags[0]->pivot->tagged_at ?? null);

        $second = $users[1];
        static::assertNull($second->profile);
        static::assertCount(1, $second->posts);
        static::assertSame('Third Post', $second->posts[0]->title);
        static::assertCount(1, $second->tags);
        static::assertSame('beta', $second->tags[0]->name);
        static::assertSame('2024-01-03', $second->tags[0]->pivot->tagged_at ?? null);

        $postRepo = new EntityRepository($connection, $factory, BlogPostEntity::class);
        $posts = $postRepo->findBy([], null, null, ['user.profile']);
        static::assertCount(3, $posts);
        foreach ($posts as $post) {
            static::assertInstanceOf(BlogUserEntity::class, $post->user);
            static::assertNotSame('', $post->user->email);
        }

        $withProfile = array_filter($posts, static fn(BlogPostEntity $post) => null !== $post->user->profile);
        static::assertCount(2, $withProfile);

        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);
        $tags = $tagRepo->findBy([], null, null, ['users']);
        static::assertCount(2, $tags);
        $alpha = array_values(array_filter($tags, static fn(BlogTagEntity $tag) => 'alpha' === $tag->name));
        static::assertCount(1, $alpha);
        static::assertCount(1, $alpha[0]->users);
    }

    public function testRepositoryRelationLimitRequiresOrderBy(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogPostEntity::class]);

        $pdo->exec("INSERT INTO users (email) VALUES ('first@example.com')");
        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Alpha')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Per-parent limit requires order by columns.');
        $repo->findBy([], null, null, [
            'posts' => new RelationOptions()->limitPerParent(1),
        ]);
    }

    public function testRepositoryManyToManyAttachDetachSyncToggle(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag1 = new BlogTagEntity();
        $tag1->name = 'alpha';
        $tagRepo->insert($tag1);

        $tag2 = new BlogTagEntity();
        $tag2->name = 'beta';
        $tagRepo->insert($tag2);

        $tag3 = new BlogTagEntity();
        $tag3->name = 'gamma';
        $tagRepo->insert($tag3);

        $attached = $userRepo->attach($user, 'tags', [
            ['id' => $tag1->id, 'pivot' => ['tagged_at' => '2024-04-01']],
            ['id' => $tag2->id, 'pivot' => ['tagged_at' => '2024-04-02']],
        ]);
        static::assertSame(['attached' => 2, 'updated' => 0, 'skipped' => 0], $attached);

        $detachedOne = $userRepo->detach($user, 'tags', [$tag2->id]);
        static::assertSame(1, $detachedOne);

        $toggle = $userRepo->toggle($user, 'tags', [$tag1->id, $tag2->id, $tag3->id], ['tagged_at' => '2024-04-03']);
        static::assertSame(['attached' => 2, 'detached' => 1], $toggle);

        $sync = $userRepo->sync($user, 'tags', [
            ['id' => $tag1->id, 'pivot' => ['tagged_at' => '2024-04-09']],
            $tag3->id,
        ]);
        static::assertSame(['attached' => 1, 'updated' => 0, 'detached' => 1], $sync);

        $freshUserRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $remaining = $freshUserRepo->find($user->id, ['tags']);
        static::assertInstanceOf(BlogUserEntity::class, $remaining);
        static::assertCount(2, $remaining->tags);

        usort($remaining->tags, static fn(BlogTagEntity $left, BlogTagEntity $right) => $left->id <=> $right->id);
        static::assertSame($tag1->id, $remaining->tags[0]->id);
        static::assertSame('2024-04-09', $remaining->tags[0]->pivot->tagged_at ?? null);
        static::assertSame($tag3->id, $remaining->tags[1]->id);

        $detachedAll = $userRepo->detach($user, 'tags');
        static::assertSame(2, $detachedAll);

        $reloadedUserRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $none = $reloadedUserRepo->find($user->id, ['tags']);
        static::assertInstanceOf(BlogUserEntity::class, $none);
        static::assertSame([], $none->tags);
    }

    public function testRepositoryManyToManyDuplicateHandlingAndPivotUpdates(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $userRepo->attach($user, 'tags', [['id' => $tag->id, 'pivot' => ['tagged_at' => '2024-05-01']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate detected');
        $userRepo->attach($user, 'tags', [$tag->id]);
    }

    public function testRepositoryManyToManyDuplicateUpdateAndIgnoreModes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $userRepo->attach($user, 'tags', [['id' => $tag->id, 'pivot' => ['tagged_at' => '2024-05-01']]]);

        $updated = $userRepo->attach(
            $user,
            'tags',
            [['id' => $tag->id, 'pivot' => ['tagged_at' => '2024-05-02']]],
            [],
            EntityRepository::DUPLICATE_BEHAVIOR_UPDATE,
        );
        static::assertSame(['attached' => 0, 'updated' => 1, 'skipped' => 0], $updated);

        $ignored = $userRepo->attach($user, 'tags', [$tag->id], [], EntityRepository::DUPLICATE_BEHAVIOR_IGNORE);
        static::assertSame(['attached' => 0, 'updated' => 0, 'skipped' => 1], $ignored);

        $row = $connection->fetchOne(
            new SelectQuery('user_tags')
                ->select(['tagged_at'])
                ->where(Condition::equals('user_id', $user->id))
                ->where(Condition::equals('tag_id', $tag->id)),
        );
        static::assertSame('2024-05-02', $row['tagged_at'] ?? null);
    }

    public function testRepositorySaveAndCreateRelatedForHasOneAndHasMany(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogPostEntity::class, BlogProfileEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $postRepo = new EntityRepository($connection, $factory, BlogPostEntity::class);
        $profileRepo = new EntityRepository($connection, $factory, BlogProfileEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $createdProfile = $userRepo->createRelated($user, 'profile', ['displayName' => 'Owner']);
        static::assertInstanceOf(BlogProfileEntity::class, $createdProfile);
        static::assertSame($user->id, $createdProfile->userId);

        $createdPost = $userRepo->createRelated($user, 'posts', ['title' => 'Hello']);
        static::assertInstanceOf(BlogPostEntity::class, $createdPost);
        static::assertSame($user->id, $createdPost->userId);

        $anotherUser = new BlogUserEntity();
        $anotherUser->email = 'second@example.com';
        $userRepo->insert($anotherUser);

        $movedPost = new BlogPostEntity();
        $movedPost->userId = $anotherUser->id;
        $movedPost->title = 'Move me';
        $postRepo->insert($movedPost);

        $saved = $userRepo->saveRelated($user, 'posts', $movedPost);
        static::assertSame(1, $saved);

        $reloaded = $postRepo->find($movedPost->id);
        static::assertInstanceOf(BlogPostEntity::class, $reloaded);
        static::assertSame($user->id, $reloaded->userId);

        $freshProfile = new BlogProfileEntity();
        $freshProfile->displayName = 'Saved Profile';
        $savedProfileResult = $userRepo->saveRelated($user, 'profile', $freshProfile);
        static::assertSame(1, $savedProfileResult);

        $profiles = $profileRepo->findBy(['userId' => $user->id], null, null, [], ['id' => 'ASC']);
        static::assertCount(2, $profiles);
        static::assertSame('Saved Profile', $profiles[1]->displayName);
    }

    public function testRepositoryAttachRejectsInvalidDuplicateBehavior(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported duplicate behavior');
        $userRepo->attach($user, 'tags', [$tag->id], [], 'invalid');
    }

    public function testRepositoryAttachRejectsInvalidPivotColumn(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pivot column "not_allowed" is not configured');
        $userRepo->attach($user, 'tags', [['id' => $tag->id, 'pivot' => ['not_allowed' => 'value']]]);
    }

    public function testRepositoryAttachRejectsNonArrayPivotPayload(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $userRepo->insert($user);

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pivot payload must be an array');
        $userRepo->attach($user, 'tags', [['id' => $tag->id, 'pivot' => 'invalid']]);
    }

    public function testRepositoryRelationWriteRejectsUnsupportedRelationType(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $user = new BlogUserEntity();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support this write operation');
        $repo->createRelated($user, 'tags', ['name' => 'alpha']);
    }

    public function testRepositoryRelationWriteRejectsUnknownRelation(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $user = new BlogUserEntity();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown relation');
        $repo->attach($user, 'missingRelation', [1]);
    }

    public function testRepositorySaveRelatedRejectsWrongRelatedEntityClass(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);

        $user = new BlogUserEntity();
        $user->email = 'owner@example.com';
        $repo->insert($user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Related entity must be instance of');
        $repo->saveRelated($user, 'posts', new BlogTagEntity());
    }

    public function testRepositoryRelationWriteRequiresRepositoryEntityType(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bulk operation entity must be instance of');
        $repo->createRelated(new \stdClass(), 'posts', ['title' => 'Invalid']);
    }

    public function testRepositoryRelationWriteRejectsUnmappedRelationKeys(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'), new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, MisconfiguredRelationUserEntity::class);

        $entity = new MisconfiguredRelationUserEntity();
        $entity->id = 1;
        $entity->email = 'owner@example.com';

        try {
            $repo->createRelated($entity, 'brokenPosts', ['title' => 'Broken']);
            static::fail('Expected exception for unmapped foreign key.');
        } catch (RuntimeException $exception) {
            static::assertStringContainsString('foreign key "missing_user_id" is not mapped', $exception->getMessage());
        }

        try {
            $repo->createRelated($entity, 'brokenProfile', ['title' => 'Broken']);
            static::fail('Expected exception for unmapped local key.');
        } catch (RuntimeException $exception) {
            static::assertStringContainsString('local key "missing_local_key" is not mapped', $exception->getMessage());
        }
    }

    public function testRepositoryRelationWriteRequiresParentKey(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogTagEntity::class]);
        $pdo->exec('CREATE TABLE user_tags (user_id INTEGER, tag_id INTEGER, tagged_at TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $userRepo = new EntityRepository($connection, $factory, BlogUserEntity::class);
        $tagRepo = new EntityRepository($connection, $factory, BlogTagEntity::class);

        $unsavedUser = new BlogUserEntity();
        $unsavedUser->email = 'unsaved@example.com';

        $tag = new BlogTagEntity();
        $tag->name = 'alpha';
        $tagRepo->insert($tag);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must reference a persisted parent');
        $userRepo->attach($unsavedUser, 'tags', [$tag->id]);
    }

    public function testRepositoryEagerLoadOptionsCallable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [BlogUserEntity::class, BlogPostEntity::class]);

        $pdo->exec("INSERT INTO users (email) VALUES ('first@example.com')");
        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Alpha')");
        $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Beta')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, BlogUserEntity::class);

        $users = $repo->findBy([], null, null, [
            'posts' => static function ($query, $baseCondition): ?Condition {
                $query->orderBy('title', 'ASC');

                return Condition::equals('title', 'Alpha');
            },
        ]);

        static::assertCount(1, $users);
        static::assertCount(1, $users[0]->posts);
        static::assertSame('Alpha', $users[0]->posts[0]->title);
    }

    public function testRepositorySelectFetchHelpers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);
        $pdo->exec("INSERT INTO users (email, display_name) VALUES ('first@example.com', 'First')");
        $pdo->exec("INSERT INTO users (email, display_name) VALUES ('second@example.com', 'Second')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $query = $repo->select()->where(Condition::equals('email', 'first@example.com'));
        $found = $repo->fetchOne($query);

        static::assertInstanceOf(UserEntity::class, $found);
        static::assertSame('First', $found->displayName);

        $all = $repo->fetchAll($repo->select());
        static::assertCount(2, $all);
    }

    public function testRepositoryInsertManyUpdatesManyDeletesMany(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $first = new UserEntity();
        $first->email = 'first@example.com';
        $first->displayName = 'First';

        $second = new UserEntity();
        $second->email = 'second@example.com';
        $second->displayName = 'Second';

        $third = new UserEntity();
        $third->email = 'third@example.com';
        $third->displayName = 'Third';

        $ids = $repo->insertMany([$first, $second, $third]);
        static::assertCount(3, $ids);
        static::assertSame(3, $repo->count());

        $first->displayName = 'First Updated';
        $second->displayName = 'Second Updated';

        $updated = $repo->updateMany([$first, $second]);
        static::assertSame(2, $updated);

        $updatedFirst = $repo->find($first->id);
        $updatedSecond = $repo->find($second->id);
        static::assertSame('First Updated', $updatedFirst->displayName);
        static::assertSame('Second Updated', $updatedSecond->displayName);

        $deleted = $repo->deleteMany([$first, $third]);
        static::assertSame(2, $deleted);
        static::assertNull($repo->find($first->id));
        static::assertNull($repo->find($third->id));
        static::assertSame(1, $repo->count());
    }

    public function testRepositoryInsertManyUsesTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $first = new UserEntity();
        $first->email = 'first@example.com';
        $first->displayName = 'First';

        $second = new UserEntity();
        $second->email = 'second@example.com';
        $second->displayName = 'Second';

        static::expectException(RuntimeException::class);
        $repo->insertMany([$first, new \stdClass(), $second]);

        static::assertSame(0, $repo->count());
    }

    public function testRepositoryBulkMethodsComposeWithOuterTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);
        $pdo->exec('CREATE UNIQUE INDEX idx_users_email_unique ON users (email)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $outer = new UserEntity();
        $outer->email = 'outer@example.com';
        $outer->displayName = 'Outer';

        $first = new UserEntity();
        $first->email = 'first@example.com';
        $first->displayName = 'First';

        $second = new UserEntity();
        $second->email = 'second@example.com';
        $second->displayName = 'Second';

        $connection->transaction(function () use ($repo, $outer, $first, $second): void {
            $repo->insert($outer);

            try {
                $repo->insertMany([$first, new \stdClass(), $second]);
            } catch (RuntimeException $exception) {
                static::assertStringContainsString('Bulk operation entity must be instance', $exception->getMessage());
            }

            $persistedOuter = $repo->findOneBy(['email' => 'outer@example.com']);
            static::assertInstanceOf(UserEntity::class, $persistedOuter);
            $persistedOuter->displayName = 'Outer Updated';
            static::assertSame(1, $repo->updateMany([$persistedOuter]));

            $upsert = new UserEntity();
            $upsert->email = 'upsert@example.com';
            $upsert->displayName = 'Upserted';
            static::assertSame(1, $repo->upsertMany([$upsert], ['email']));

            static::assertSame(1, $repo->deleteMany([$upsert]));
        });

        static::assertSame(1, $repo->count());
        $saved = $repo->findOneBy(['email' => 'outer@example.com']);
        static::assertInstanceOf(UserEntity::class, $saved);
        static::assertSame('Outer Updated', $saved->displayName);
        static::assertNull($repo->findOneBy(['email' => 'first@example.com']));
        static::assertNull($repo->findOneBy(['email' => 'second@example.com']));
        static::assertNull($repo->findOneBy(['email' => 'upsert@example.com']));
    }

    public function testRepositoryIdentityMapReturnsSameInstance(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $user = new UserEntity();
        $user->email = 'identity@example.com';
        $user->displayName = 'Identity';
        $repo->insert($user);

        $found = $repo->find($user->id);
        static::assertSame($user, $found);

        $results = $repo->findBy(['email' => 'identity@example.com']);
        static::assertCount(1, $results);
        static::assertSame($user, $results[0]);
    }

    public function testRepositoryFindByOrderAndPagination(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        foreach (['c@example.com', 'a@example.com', 'b@example.com', 'd@example.com', 'e@example.com'] as $email) {
            $user = new UserEntity();
            $user->email = $email;
            $user->displayName = $email;
            $repo->insert($user);
        }

        $ordered = $repo->findBy([], null, null, [], ['email' => 'ASC']);
        $emails = array_map(static fn(UserEntity $user) => $user->email, $ordered);
        static::assertSame(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com'], $emails);

        $latest = $repo->findOneBy([], [], ['email' => 'DESC']);
        static::assertSame('e@example.com', $latest->email);

        $page = $repo->findPage(2, 2, [], [], ['email' => 'ASC']);
        static::assertSame(5, $page['total']);
        static::assertSame(2, $page['page']);
        static::assertSame(2, $page['perPage']);

        $pageEmails = array_map(static fn(UserEntity $user) => $user->email, $page['items']);
        static::assertSame(['c@example.com', 'd@example.com'], $pageEmails);

        $wherePage = $repo->findPageWhere(Condition::greaterThan('email', 'b@example.com'), 1, 2, [], ['email' => 'ASC']);
        $whereEmails = array_map(static fn(UserEntity $user) => $user->email, $wherePage['items']);
        static::assertSame(['c@example.com', 'd@example.com'], $whereEmails);
    }

    public function testRepositorySelectColumnsAndGroupByHelpers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $pdo->exec("INSERT INTO users (email, display_name) VALUES ('alpha@example.com', 'Alpha')");
        $pdo->exec("INSERT INTO users (email, display_name) VALUES ('alpha@example.com', 'Alpha Two')");
        $pdo->exec("INSERT INTO users (email, display_name) VALUES ('beta@example.com', 'Beta')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $grouped = $repo->selectColumns(['email']);
        $grouped->selectCount('*', 'total');
        $repo->groupByColumns($grouped, ['email']);
        $grouped->having(Condition::greaterThan('total', 1));
        $repo->orderByColumns($grouped, ['email' => 'ASC']);

        $results = $repo->fetchAll($grouped);
        static::assertCount(1, $results);
        static::assertSame('alpha@example.com', $results[0]->email);

        $aliasQuery = $repo->selectColumnsAs(['displayName' => 'displayName']);
        $aliasQuery->where(Condition::equals('email', 'beta@example.com'));
        $aliasResult = $repo->fetchOne($aliasQuery);
        static::assertSame('Beta', $aliasResult->displayName);
    }

    public function testRepositoryCursorAndChunkedByIdIterateEntities(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $user = new UserEntity();
            $user->email = $email;
            $user->displayName = $email;
            $repo->insert($user);
        }

        $cursorEmails = [];
        foreach ($repo->cursor($repo->select()->orderBy('id', 'ASC')) as $user) {
            $cursorEmails[] = $user->email;
        }
        static::assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $cursorEmails);

        $chunked = [];
        foreach ($repo->chunked($repo->select()->orderBy('id', 'ASC'), 2) as $chunk) {
            $chunked[] = array_map(static fn(UserEntity $user) => $user->email, $chunk);
        }
        static::assertSame([['a@example.com', 'b@example.com'], ['c@example.com']], $chunked);

        $cursorById = [];
        foreach ($repo->cursorById(null, [], 'id', 'DESC') as $user) {
            $cursorById[] = $user->email;
        }
        static::assertSame(['c@example.com', 'b@example.com', 'a@example.com'], $cursorById);

        $chunkedById = [];
        foreach ($repo->chunkedById(2, null, [], 'id', 'ASC') as $chunk) {
            $chunkedById[] = array_map(static fn(UserEntity $user) => $user->email, $chunk);
        }
        static::assertSame([['a@example.com', 'b@example.com'], ['c@example.com']], $chunkedById);
    }

    public function testRepositorySoftDeleteScopes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [SoftDeleteUserEntity::class]);

        $pdo->exec("INSERT INTO soft_delete_users (email, deleted_at) VALUES ('active@example.com', NULL)");
        $pdo->exec(
            "INSERT INTO soft_delete_users (email, deleted_at) VALUES ('deleted@example.com', '2024-01-01 00:00:00')",
        );

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, SoftDeleteUserEntity::class);

        $default = $repo->findBy();
        static::assertCount(1, $default);
        static::assertSame('active@example.com', $default[0]->email);

        $withTrashed = $repo->withTrashed()->findBy();
        static::assertCount(2, $withTrashed);

        $onlyTrashed = $repo->onlyTrashed()->findBy();
        static::assertCount(1, $onlyTrashed);
        static::assertSame('deleted@example.com', $onlyTrashed[0]->email);
    }

    public function testRepositoryAppliesPhaseAwareValidators(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [PhaseValidatedEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, PhaseValidatedEntity::class);

        $entity = new PhaseValidatedEntity();
        $entity->username = '';

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('This field cannot be blank');
        $repo->insert($entity);
    }

    public function testRepositoryAppliesUpdateOnlyValidatorRules(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [PhaseValidatedEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, PhaseValidatedEntity::class);

        $entity = new PhaseValidatedEntity();
        $entity->username = 'UPPERCASE';
        $repo->insert($entity);

        $entity->username = 'Invalid123';

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('Username must be lowercase letters only on update.');
        $repo->save($entity);
    }

    public function testRepositoryHydrateValidationIsOptIn(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [ValidatedUserEntity::class]);
        $pdo->exec("INSERT INTO validated_users (username) VALUES ('has space')");

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, ValidatedUserEntity::class);

        $rows = $repo->findBy();
        static::assertCount(1, $rows);

        $this->expectException(\arabcoders\database\Validator\ValidationException::class);
        $this->expectExceptionMessage('Value must not contain spaces.');
        $repo->withHydrateValidation()->findBy();
    }

    public function testRepositoryUpsertDefaultsToPrimaryKeyConflict(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $user = new UserEntity();
        $user->id = 1;
        $user->email = 'upsert@example.com';
        $user->displayName = 'First';
        $repo->upsert($user);

        $user->displayName = 'Updated';
        $repo->upsert($user);

        $found = $repo->find(1);
        static::assertInstanceOf(UserEntity::class, $found);
        static::assertSame('Updated', $found->displayName);
        static::assertSame(1, $repo->count());
    }

    public function testRepositoryUpsertManyWithConflictColumns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo, [UserEntity::class]);
        $pdo->exec('CREATE UNIQUE INDEX idx_users_email_unique ON users (email)');

        $connection = new Connection($pdo, new SqliteDialect());
        $factory = new EntityMetadataFactory();
        $repo = new EntityRepository($connection, $factory, UserEntity::class);

        $first = new UserEntity();
        $first->email = 'first@example.com';
        $first->displayName = 'First';

        $updated = new UserEntity();
        $updated->email = 'first@example.com';
        $updated->displayName = 'First Updated';

        $second = new UserEntity();
        $second->email = 'second@example.com';
        $second->displayName = 'Second';

        $affected = $repo->upsertMany([$first, $updated, $second], ['email']);
        static::assertGreaterThanOrEqual(2, $affected);
        static::assertSame(2, $repo->count());

        $row = $repo->findOneBy(['email' => 'first@example.com']);
        static::assertInstanceOf(UserEntity::class, $row);
        static::assertSame('First Updated', $row->displayName);
    }

    public function testRepositoryIsolationAcrossNamedConnections(): void
    {
        $primaryPdo = new PDO('sqlite::memory:');
        $primaryPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($primaryPdo, [UserEntity::class]);

        $analyticsPdo = new PDO('sqlite::memory:');
        $analyticsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($analyticsPdo, [UserEntity::class]);

        $primaryConnection = new Connection($primaryPdo, new SqliteDialect());
        $analyticsConnection = new Connection($analyticsPdo, new SqliteDialect());

        $connections = new ConnectionManager();
        $connections->register('default', $primaryConnection);
        $connections->register('analytics', $analyticsConnection);

        $orm = new OrmManager($connections);
        $primaryRepo = $orm->repository(UserEntity::class);
        $analyticsRepo = $orm->repository(UserEntity::class, 'analytics');

        $primaryUser = new UserEntity();
        $primaryUser->email = 'primary@example.com';
        $primaryUser->displayName = 'Primary';
        $primaryRepo->insert($primaryUser);

        $analyticsUser = new UserEntity();
        $analyticsUser->email = 'analytics@example.com';
        $analyticsUser->displayName = 'Analytics';
        $analyticsRepo->insert($analyticsUser);

        static::assertSame(1, $primaryRepo->count());
        static::assertSame(1, $analyticsRepo->count());
        static::assertNull($primaryRepo->findOneBy(['email' => 'analytics@example.com']));
        static::assertNull($analyticsRepo->findOneBy(['email' => 'primary@example.com']));
    }

    /**
     * @param array<int,class-string> $models
     */
    private function createSchema(PDO $pdo, array $models): void
    {
        foreach (SchemaGenerator::generateSchemas($models, 'sqlite')->up as $statement) {
            $pdo->exec($statement);
        }
    }
}
