<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Migration\MigrationRegistry;
use arabcoders\database\Schema\Migration\MigrationReplay;
use arabcoders\database\Schema\Migration\MigrationTemplate;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigrationExporter;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use RuntimeException;

final readonly class MigrationSquasher
{
    public function __construct(
        private string $migrationDirectory,
        private MigrationTemplate $template,
    ) {}

    /**
     * Squash migrations from the token up to the latest migration.
     * If $apply is false the method returns a report and the generated content without modifying files.
     * If $apply is true the latest migration file is overwritten and earlier files in the range are removed.
     *
     * @return array{start:string,end:string,latestFile:string,newContents:string,deletedFiles:string[]}
     */
    public function squash(#[\SensitiveParameter] string $token, bool $apply = false): array
    {
        $token = trim($token);
        if ('' === $token) {
            throw new RuntimeException('Migration token is required.');
        }

        $registry = new MigrationRegistry([$this->migrationDirectory]);
        $definitions = $registry->all();
        if (empty($definitions)) {
            throw new RuntimeException('No migrations found.');
        }

        $matches = $this->findMatches($definitions, $token);
        if (0 === count($matches)) {
            throw new RuntimeException('No matching migration found.');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Multiple migrations match the token. Use a more specific token.');
        }

        $startIndex = $matches[0];
        $endIndex = count($definitions) - 1;

        if ($startIndex >= $endIndex) {
            throw new RuntimeException('Nothing to squash: starting migration must be earlier than the latest migration.');
        }

        $combinedOperations = [];
        $fromSchema = new SchemaDefinition();
        $toSchema = new SchemaDefinition();

        $state = new SchemaDefinition();
        for ($i = 0; $i <= $endIndex; $i++) {
            $instance = $this->createMigrationInstance($definitions[$i]->class);
            $blueprint = new Blueprint($state);
            MigrationReplay::invoke($instance, $blueprint, new SqliteDialect());

            $diff = $blueprint->toHistoricalDiff($state);
            if ($i === $startIndex) {
                $fromSchema = $diff->from;
            }
            $state = $diff->to;
            if ($i >= $startIndex) {
                $toSchema = $diff->to;
            }

            if ($i >= $startIndex) {
                foreach ($diff->getOperations() as $op) {
                    $combinedOperations[] = $op;
                }
            }
        }

        if (empty($combinedOperations)) {
            throw new RuntimeException('No operations to squash.');
        }

        $exporter = new SchemaBlueprintMigrationExporter();
        $latest = $definitions[$endIndex];
        $first = $definitions[$startIndex];
        $shortLatestClass = preg_replace('/.*\\\\/', '', $latest->class) ?: $latest->class;
        $latestFile = $this->migrationDirectory . DIRECTORY_SEPARATOR . $shortLatestClass . '.php';
        $latestChecksum = hash_file('sha256', $latestFile);
        if (!is_string($latestChecksum)) {
            throw new RuntimeException('Failed to checksum migration file: ' . $latestFile);
        }
        $newContents = $exporter->export(
            new SchemaMigrationPlan($fromSchema, $toSchema, $combinedOperations),
            $shortLatestClass,
            $latest->id,
            $latest->name,
            template: $this->template,
            squashedFrom: '' !== $first->squashedFrom ? $first->squashedFrom : $first->id,
            squashedChecksum: $latestChecksum,
        );

        $deleted = [];
        if ($apply) {
            if (!is_dir(dirname($latestFile)) && !@mkdir(dirname($latestFile), 0o755, true) && !is_dir(dirname($latestFile))) {
                throw new RuntimeException('Unable to ensure migration directory exists.');
            }

            if (false === @file_put_contents($latestFile, $newContents)) {
                throw new RuntimeException('Failed to write consolidated migration file: ' . $latestFile);
            }

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $short = preg_replace('/.*\\\\/', '', $definitions[$i]->class) ?: $definitions[$i]->class;
                $file = $this->migrationDirectory . DIRECTORY_SEPARATOR . $short . '.php';
                if (is_file($file)) {
                    if (!@unlink($file)) {
                        throw new RuntimeException('Failed to delete migration file: ' . $file);
                    }
                    $deleted[] = $file;
                }
            }
        }

        return [
            'start' => $definitions[$startIndex]->id,
            'end' => $latest->id,
            'latestFile' => $latestFile,
            'newContents' => $newContents,
            'deletedFiles' => $deleted,
        ];
    }

    /**
     * @param array<int,\arabcoders\database\Schema\Migration\MigrationDefinition> $definitions
     * @return array<int,int>
     */
    private function findMatches(array $definitions, #[\SensitiveParameter] string $token): array
    {
        $token = strtolower($token);
        $matches = [];

        foreach ($definitions as $index => $migration) {
            $id = strtolower((string) $migration->id);
            $name = strtolower((string) $migration->name);

            if (ctype_digit($token)) {
                if (str_starts_with($id, $token)) {
                    $matches[] = $index;
                }
                continue;
            }

            if (str_contains($name, $token)) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    private function createMigrationInstance(string $class): SchemaBlueprintMigration
    {
        $instance = new $class();
        if (!$instance instanceof SchemaBlueprintMigration) {
            throw new RuntimeException(sprintf('Migration %s must extend %s.', $class, SchemaBlueprintMigration::class));
        }

        return $instance;
    }
}
