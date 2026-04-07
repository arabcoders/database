<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\Migration\MigrationFileRenderer;
use arabcoders\database\Schema\Migration\MigrationTemplate;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigrationExporter;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use arabcoders\database\Schema\SchemaDiffer;
use arabcoders\database\Schema\SchemaIntrospectOptions;
use arabcoders\database\Schema\SchemaIntrospector;
use arabcoders\database\Schema\SchemaNormalizer;
use arabcoders\database\Schema\SchemaRegistry;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PDO;
use RuntimeException;

final class MigrationCreator
{
    public function __construct(
        private string $migrationDirectory,
        private MigrationTemplate $template,
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    /**
     * Create an empty migration draft file from a normalized migration name.
     *
     * @param string $name Human-readable migration name.
     * @param ?callable $idGenerator Optional custom migration id generator.
     * @return MigrationDraft
     * @throws RuntimeException If the normalized migration name is empty.
     */
    public function createBlank(string $name, ?callable $idGenerator = null): MigrationDraft
    {
        $slug = $this->normalizeName($name);
        if ('' === $slug) {
            throw new RuntimeException('Migration name is required.');
        }

        $timestamp = $this->createTimestamp($idGenerator);
        $className = 'Migration_' . $timestamp;
        $directory = $this->migrationDirectory;
        $fileName = $className . '.php';
        $file = $directory . DIRECTORY_SEPARATOR . $fileName;

        $template = new MigrationFileRenderer()->renderBlank(
            className: $className,
            id: $timestamp,
            name: $slug,
            template: $this->template,
        );

        return new MigrationDraft(
            directory: $directory,
            fileName: $fileName,
            filePath: $file,
            className: $className,
            id: $timestamp,
            name: $slug,
            contents: $template,
        );
    }

    /**
     * Build a migration draft by diffing database schema against model schema.
     *
     * @param string $name Human-readable migration name.
     * @param PDO $pdo Active PDO connection.
     * @param array<int,string> $modelPaths
     * @param array<int,string> $ignoreTables
     * @param bool $dropOrphans Whether unmanaged tables should be included in diffing.
     * @param bool $dryRun Whether to return SQL preview instead of a file draft.
     * @param ?callable $idGenerator Optional custom migration id generator.
     * @return MigrationDraft|MigrationPreview
     * @throws RuntimeException If no models are discovered, no changes exist, or migration naming is invalid.
     */
    public function createAutogen(
        string $name,
        PDO $pdo,
        array $modelPaths,
        array $ignoreTables = ['migration_version'],
        bool $dropOrphans = false,
        bool $dryRun = false,
        ?callable $idGenerator = null,
    ): MigrationDraft|MigrationPreview {
        return $this->createAutogenWithOptions(
            $name,
            $pdo,
            $modelPaths,
            new MigrationAutogenOptions(
                introspect: new SchemaIntrospectOptions(ignoreTables: $ignoreTables),
                dropOrphans: $dropOrphans,
                dryRun: $dryRun,
            ),
            $idGenerator,
        );
    }

    /**
     * Build a migration draft by diffing database schema against model schema.
     *
     * @param string $name Human-readable migration name.
     * @param PDO $pdo Active PDO connection.
     * @param array<int,string> $modelPaths
     * @param MigrationAutogenOptions $options Autogen options.
     * @param ?callable $idGenerator Optional custom migration id generator.
     * @return MigrationDraft|MigrationPreview
     * @throws RuntimeException If no models are discovered, no changes exist, or migration naming is invalid.
     */
    public function createAutogenWithOptions(
        string $name,
        PDO $pdo,
        array $modelPaths,
        MigrationAutogenOptions $options,
        ?callable $idGenerator = null,
    ): MigrationDraft|MigrationPreview {
        $slug = $this->normalizeName($name);
        if ('' === $slug) {
            throw new RuntimeException('Migration name is required.');
        }

        $registry = new SchemaRegistry($modelPaths, $this->container);
        $modelSchema = $registry->build();
        if (empty($modelSchema->getTables())) {
            throw new RuntimeException('No model schema attributes found.', 400);
        }

        $introspector = new SchemaIntrospector($pdo);
        $dbSchema = $introspector->introspect($options->introspectOptions());

        if (!$options->dropOrphans) {
            $dbSchema = $this->filterSchemaToModels($dbSchema, $modelSchema);
        }

        $dialect = SchemaDialectFactory::fromPdo($pdo);
        foreach ($options->augmenters as $augmenter) {
            $augmenter->augmentTargetSchema($modelSchema, $dbSchema, $dialect, $pdo);
        }

        $normalizer = new SchemaNormalizer();
        $modelSchema = $normalizer->normalize($modelSchema, $dialect);
        $dbSchema = $normalizer->normalize($dbSchema, $dialect);

        $diff = new SchemaDiffer()->diff($dbSchema, $modelSchema);
        $renderer = new SchemaSqlRenderer($dialect);
        $sql = $renderer->render($diff);

        if ($sql->isEmpty()) {
            throw new RuntimeException('No schema changes found.', 400);
        }

        if ($options->dryRun) {
            return new MigrationPreview($sql->up, $sql->down);
        }

        $timestamp = $this->createTimestamp($idGenerator);
        $className = 'Migration_' . $timestamp;
        $directory = $this->migrationDirectory;
        $fileName = $className . '.php';
        $file = $directory . DIRECTORY_SEPARATOR . $fileName;

        $plan = new SchemaMigrationPlan($dbSchema, $modelSchema, $diff->getOperations());
        $template = new SchemaBlueprintMigrationExporter()->export(
            plan: $plan,
            className: $className,
            id: $timestamp,
            name: $slug,
            template: $this->template,
        );

        return new MigrationDraft(
            directory: $directory,
            fileName: $fileName,
            filePath: $file,
            className: $className,
            id: $timestamp,
            name: $slug,
            contents: $template,
        );
    }

    /**
     * Persist a generated migration draft to disk.
     *
     * @param MigrationDraft $draft Draft migration file metadata and contents.
     * @return void
     * @throws RuntimeException If the directory cannot be created or the file cannot be written.
     */
    public function persist(MigrationDraft $draft): void
    {
        if (!is_dir($draft->directory) && !@mkdir($draft->directory, 0o755, true) && !is_dir($draft->directory)) {
            throw new RuntimeException('Unable to create db migration directory.');
        }

        if (file_exists($draft->filePath)) {
            throw new RuntimeException('Migration already exists.');
        }

        if (false === file_put_contents($draft->filePath, $draft->contents)) {
            throw new RuntimeException('Failed to write migration file.');
        }
    }

    private function normalizeName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }

    private function createTimestamp(?callable $idGenerator): string
    {
        if (null !== $idGenerator) {
            $value = (string) $idGenerator();
            if ('' !== trim($value)) {
                return $value;
            }
        }

        return date('ymdHis');
    }

    private function filterSchemaToModels(SchemaDefinition $dbSchema, SchemaDefinition $modelSchema): SchemaDefinition
    {
        $filtered = new SchemaDefinition();
        $managedTables = [];

        foreach ($modelSchema->getTables() as $tableName => $table) {
            $managedTables[$tableName] = true;
            if (null !== $table->previousName) {
                $managedTables[$table->previousName] = true;
            }
        }

        foreach (array_keys($managedTables) as $tableName) {
            $table = $dbSchema->getTable($tableName);
            if (null !== $table) {
                $filtered->addTable($table);
            }
        }

        return $filtered;
    }
}
