<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Migration\MigrationRegistry;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
use arabcoders\database\Schema\Migration\SchemaBlueprintMigrationExporter;
use arabcoders\database\Schema\Migration\SchemaMigrationPlan;
use PDO;
use RuntimeException;

final class MigrationSquasher
{
    public function __construct(
        private string $migrationDirectory,
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

        // Use an in-memory PDO + Connection to execute migration callables into a Blueprint only
        $scratchPdo = new PDO('sqlite::memory:');
        $scratchPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $scratchConnection = new Connection($scratchPdo, DialectFactory::fromPdo($scratchPdo));

        for ($i = $startIndex; $i <= $endIndex; $i++) {
            $class = $definitions[$i]->class;
            $instance = new $class();
            if (!$instance instanceof SchemaBlueprintMigration) {
                throw new RuntimeException(sprintf('Migration %s must extend %s.', $class, SchemaBlueprintMigration::class));
            }

            $blueprint = new Blueprint();
            $instance($scratchConnection, $blueprint);
            $diff = $blueprint->toDiff();
            $ops = $diff->getOperations();

            foreach ($ops as $op) {
                $combinedOperations[] = $op;
            }
        }

        if (empty($combinedOperations)) {
            throw new RuntimeException('No operations to squash.');
        }

        $exporter = new SchemaBlueprintMigrationExporter();
        $plan = new SchemaMigrationPlan(new SchemaDefinition(), new SchemaDefinition(), $combinedOperations);

        $latest = $definitions[$endIndex];
        $shortLatestClass = preg_replace('/.*\\\\/', '', $latest->class) ?: $latest->class;
        $newContents = $exporter->export($plan, $shortLatestClass, $latest->id, $latest->name);
        $latestFile = $this->migrationDirectory . DIRECTORY_SEPARATOR . $shortLatestClass . '.php';

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
}
