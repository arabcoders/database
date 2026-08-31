<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\MigrationAutogenOptions;
use arabcoders\database\Commands\MigrationCreator;
use arabcoders\database\Commands\MigrationDraft;
use arabcoders\database\Commands\MigrationPreview;
use arabcoders\database\Schema\AutogenSchemaAugmenterInterface;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use arabcoders\database\Schema\Migration\MigrationTemplate;
use arabcoders\database\Schema\Migration\SchemaBlueprintRunner;
use arabcoders\database\Schema\SchemaIntrospector;
use PDO;
use SplFileInfo;
use tests\TestCase;

final class MigrationCreatorTest extends TestCase
{
    public function testAutogenPreviewApplies(): void
    {
        $pdo = $this->memoryPdo();
        $this->createUserProfileTable($pdo, includeDisplayName: false);
        $this->createUserProfileModelIndexes($pdo);

        $result = $this->creator()->createAutogen(
            'add display name',
            $pdo,
            $this->userProfileModelPaths(),
            dryRun: true,
        );

        static::assertInstanceOf(MigrationPreview::class, $result);
        $previewPdo = $this->memoryPdo();
        $previewPdo->exec('CREATE TABLE user_profile (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255) NOT NULL)');
        $this->createUserProfileModelIndexes($previewPdo);
        foreach ($result->up as $statement) {
            $previewPdo->exec($statement);
        }

        $schema = new SchemaIntrospector($previewPdo)->introspect();
        static::assertNotNull($schema->getTable('user_profile')?->getColumn('display_name'));
    }

    public function testAutogenPreservesIndexes(): void
    {
        $pdo = $this->memoryPdo();
        $this->createUserProfileTable($pdo, includeDisplayName: false);
        $this->createUserProfileModelIndexes($pdo);
        $this->createUserProfileExternalIndexes($pdo);

        $result = $this->creator()->createAutogenWithOptions(
            'preserve external indexes',
            $pdo,
            $this->userProfileModelPaths(),
            new MigrationAutogenOptions(
                dryRun: false,
                augmenters: [$this->externalIndexAugmenter()],
            ),
            static fn(): string => '240101000002',
        );

        static::assertInstanceOf(MigrationDraft::class, $result);
        $this->creator()->persist($result);
        require_once $result->filePath;
        $class = 'Migration\\' . $result->className;
        new SchemaBlueprintRunner($pdo)->run(new $class(), 'up');

        $schema = new SchemaIntrospector($pdo)->introspect();
        static::assertNotNull($schema->getTable('user_profile')?->getColumn('display_name'));
        static::assertNotNull($schema->getTable('user_profile')?->getIndex('idx_user_profile_email_external'));
        static::assertNotNull($schema->getTable('user_profile')?->getIndex('idx_user_profile_email_lower_external'));
    }

    public function testAutogenAugmenterRecreates(): void
    {
        $pdo = $this->memoryPdo();
        $this->createUserProfileTable($pdo, includeLegacy: true);
        $this->createUserProfileModelIndexes($pdo);
        $this->createUserProfileExternalIndexes($pdo);

        $result = $this->creator()->createAutogenWithOptions(
            'drop legacy column',
            $pdo,
            $this->userProfileModelPaths(),
            new MigrationAutogenOptions(
                dryRun: true,
                augmenters: [$this->externalIndexAugmenter()],
            ),
        );

        static::assertInstanceOf(MigrationPreview::class, $result);
        foreach ($result->up as $statement) {
            $pdo->exec($statement);
        }

        $schema = new SchemaIntrospector($pdo)->introspect();
        static::assertNull($schema->getTable('user_profile')?->getColumn('legacy'));
        static::assertNotNull($schema->getTable('user_profile')?->getIndex('idx_user_profile_email_external'));
        static::assertNotNull($schema->getTable('user_profile')?->getIndex('idx_user_profile_email_lower_external'));
    }

    public function testAutogenMigrationPreserves(): void
    {
        $pdo = $this->memoryPdo();
        $this->createUserProfileTable($pdo, includeLegacy: true);
        $this->createUserProfileModelIndexes($pdo);
        $this->createUserProfileExternalIndexes($pdo);
        $pdo->exec(
            "INSERT INTO user_profile (email, display_name, legacy) VALUES ('person@example.com', 'Person', 'remove me')",
        );

        $directory = $this->tempDir('migration-creator');
        $creator = new MigrationCreator($directory, new MigrationTemplate());
        $draft = $creator->createAutogenWithOptions(
            'drop legacy column',
            $pdo,
            $this->userProfileModelPaths(),
            new MigrationAutogenOptions(
                augmenters: [$this->externalIndexAugmenter()],
            ),
            static fn(): string => '240101000003',
        );

        static::assertInstanceOf(MigrationDraft::class, $draft);
        $creator->persist($draft);

        require_once $draft->filePath;

        $class = 'Migration\\' . $draft->className;
        $runner = new SchemaBlueprintRunner($pdo);
        $runner->run(new $class(), 'up');

        $schema = new SchemaIntrospector($pdo)->introspect();
        $table = $schema->getTable('user_profile');

        static::assertNotNull($table);
        static::assertNull($table->getColumn('legacy'));
        static::assertNotNull($table->getColumn('display_name'));
        static::assertSame(['id'], $table->getPrimaryKey());
        static::assertTrue($table->getIndex('uniq_user_profile_email')?->unique);
        static::assertNotNull($table->getIndex('idx_user_profile_email_external'));
        static::assertNotNull($table->getIndex('idx_user_profile_email_lower_external'));
        static::assertSame(
            "email <> ''",
            $table->getIndex('idx_user_profile_email_partial_external')?->where,
        );
        static::assertSame(
            ['email' => 'person@example.com'],
            $pdo->query('SELECT email FROM user_profile')->fetch(PDO::FETCH_ASSOC),
        );
    }

    private function creator(): MigrationCreator
    {
        return new MigrationCreator($this->tempDir('migration-creator'), new MigrationTemplate());
    }

    /**
     * @return array<int,array{dir:string,filter:callable(SplFileInfo):bool}>
     */
    private function userProfileModelPaths(): array
    {
        return [[
            'dir' => TESTS_PATH . '/fixtures/Schema',
            'filter' => static fn(SplFileInfo $file): bool => 'UserProfile.php' === $file->getFilename(),
        ]];
    }

    private function createUserProfileTable(PDO $pdo, bool $includeDisplayName = true, bool $includeLegacy = false): void
    {
        $columns = [
            'id INTEGER PRIMARY KEY AUTOINCREMENT',
            'email VARCHAR(255) NOT NULL',
        ];

        if ($includeDisplayName) {
            $columns[] = 'display_name VARCHAR(255) NOT NULL';
        }

        if ($includeLegacy) {
            $columns[] = 'legacy TEXT NULL';
        }

        $pdo->exec('CREATE TABLE user_profile (' . implode(', ', $columns) . ')');
    }

    private function createUserProfileModelIndexes(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX idx_user_profile_email ON user_profile(email)');
        $pdo->exec('CREATE UNIQUE INDEX uniq_user_profile_email ON user_profile(email)');
    }

    private function createUserProfileExternalIndexes(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX idx_user_profile_email_external ON user_profile(email)');
        $pdo->exec('CREATE INDEX idx_user_profile_email_lower_external ON user_profile((lower(email)))');
        $pdo->exec("CREATE INDEX idx_user_profile_email_partial_external ON user_profile(email) WHERE email <> ''");
    }

    private function externalIndexAugmenter(): AutogenSchemaAugmenterInterface
    {
        return new class implements AutogenSchemaAugmenterInterface {
            public function augmentTargetSchema(
                SchemaDefinition $targetSchema,
                SchemaDefinition $databaseSchema,
                SchemaDialectInterface $dialect,
                PDO $pdo,
            ): void {
                foreach ($databaseSchema->getTables() as $tableName => $databaseTable) {
                    $targetTable = $targetSchema->getTable($tableName);
                    if (null === $targetTable) {
                        continue;
                    }

                    foreach ($databaseTable->getIndexes() as $index) {
                        if (!str_ends_with($index->name, '_external')) {
                            continue;
                        }

                        $targetTable->addIndex($index);
                    }
                }
            }
        };
    }
}
