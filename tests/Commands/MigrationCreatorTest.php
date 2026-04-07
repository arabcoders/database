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
use PDO;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class MigrationCreatorTest extends TestCase
{
    public function testCreateAutogenRemainsBackwardCompatible(): void
    {
        $pdo = $this->createSqliteConnection();
        $this->createUserProfileTable($pdo, includeDisplayName: false);
        $this->createUserProfileModelIndexes($pdo);

        $result = $this->creator()->createAutogen(
            'add display name',
            $pdo,
            $this->userProfileModelPaths(),
            dryRun: true,
        );

        static::assertInstanceOf(MigrationPreview::class, $result);
        $sql = implode("\n", $result->up);
        static::assertStringContainsString('RENAME TO "_tmp_user_profile_old"', $sql);
        static::assertStringContainsString('"display_name" VARCHAR(255) NOT NULL', $sql);
    }

    public function testCreateAutogenWithAugmenterRemovesExternalIndexDropsFromDraft(): void
    {
        $pdo = $this->createSqliteConnection();
        $this->createUserProfileTable($pdo, includeDisplayName: false);
        $this->createUserProfileModelIndexes($pdo);
        $this->createUserProfileExternalIndexes($pdo);

        $baseline = $this->creator()->createAutogen(
            'preserve external indexes',
            $pdo,
            $this->userProfileModelPaths(),
            dryRun: false,
            idGenerator: static fn(): string => '240101000001',
        );
        static::assertInstanceOf(MigrationDraft::class, $baseline);
        static::assertStringContainsString("dropIndex('idx_user_profile_email_external'", $baseline->contents);
        static::assertStringContainsString("dropIndex('idx_user_profile_email_lower_external'", $baseline->contents);

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
        static::assertStringContainsString('display_name', $result->contents);
        static::assertStringNotContainsString("dropIndex('idx_user_profile_email_external'", $result->contents);
        static::assertStringNotContainsString("dropIndex('idx_user_profile_email_lower_external'", $result->contents);
    }

    public function testCreateAutogenWithAugmenterRecreatesExternalIndexesDuringSqliteRebuild(): void
    {
        $pdo = $this->createSqliteConnection();
        $this->createUserProfileTable($pdo, includeLegacy: true);
        $this->createUserProfileModelIndexes($pdo);
        $this->createUserProfileExternalIndexes($pdo);

        $baseline = $this->creator()->createAutogen(
            'drop legacy column',
            $pdo,
            $this->userProfileModelPaths(),
            dryRun: true,
        );
        static::assertInstanceOf(MigrationPreview::class, $baseline);
        $baselineSql = implode("\n", $baseline->up);
        static::assertStringContainsString('RENAME TO "_tmp_user_profile_old"', $baselineSql);
        static::assertStringNotContainsString('idx_user_profile_email_external', $baselineSql);
        static::assertStringNotContainsString('idx_user_profile_email_lower_external', $baselineSql);

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
        $sql = implode("\n", $result->up);
        static::assertStringContainsString('RENAME TO "_tmp_user_profile_old"', $sql);
        static::assertStringContainsString('CREATE INDEX "idx_user_profile_email_external" ON "user_profile" ("email")', $sql);
        static::assertStringContainsString('CREATE INDEX "idx_user_profile_email_lower_external" ON "user_profile" ((lower(email)))', $sql);
    }

    private function creator(): MigrationCreator
    {
        return new MigrationCreator(sys_get_temp_dir() . '/ac-database-tests', new MigrationTemplate());
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

    private function createSqliteConnection(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
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
