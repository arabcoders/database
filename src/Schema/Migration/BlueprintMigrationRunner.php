<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use PDO;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class BlueprintMigrationRunner
{
    private const string LOCK_KEY = 'schema_migration';

    public function __construct(
        private PDO $pdo,
        private MigrationRegistry $registry,
        private string $versionTable = 'migration_version',
        private string $lockTable = 'migration_lock',
    ) {}

    /**
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    public function migrate(
        string $direction = 'up',
        bool $dryRun = false,
        int $steps = 0,
        bool $force = false,
        bool $repair = false,
    ): array {
        $direction = $this->normalizeDirection($direction);

        $migrations = $this->loadMigrations();

        $this->ensureVersionTable();
        $this->ensureLockTable();

        $requiresLock = !$dryRun || $repair;
        if ($requiresLock) {
            return $this->withLock(function () use ($direction, $migrations, $dryRun, $steps, $force, $repair): array {
                $this->validateAppliedState($migrations, $force, $repair);

                if (empty($migrations)) {
                    return [];
                }

                if ('up' === $direction) {
                    return $this->applyUp($migrations, $dryRun, $steps, $force);
                }

                $effectiveSteps = $steps > 0 ? $steps : 1;
                return $this->applyDown($migrations, $dryRun, $effectiveSteps);
            }, $force);
        }

        $this->validateAppliedState($migrations, $force, false);
        if (empty($migrations)) {
            return [];
        }

        if ('up' === $direction) {
            return $this->applyUp($migrations, true, $steps, $force);
        }

        $effectiveSteps = $steps > 0 ? $steps : 1;
        return $this->applyDown($migrations, true, $effectiveSteps);
    }

    /**
     * Inspect migration state without mutating metadata tables or taking a lock.
     *
     * @return array{
     *   direction:string,
     *   needed:bool,
     *   migrations:array<int,array{id:string,name:string,class:string,checksum:string}>,
     *   lock:array{table:string,locked:bool,holder:?string,acquired_at:?int},
     *   issues:array<int,string>
     * }
     */
    public function probe(
        string $direction = 'up',
        int $steps = 0,
        bool $force = false,
        bool $repair = false,
    ): array {
        $direction = $this->normalizeDirection($direction);
        $migrations = $this->loadMigrations();
        $appliedVersions = $this->probeAppliedVersions();

        $pending = 'up' === $direction
            ? $this->selectUpMigrations($migrations, $appliedVersions, $steps)
            : $this->selectDownMigrations($migrations, $appliedVersions, $steps);

        return [
            'direction' => $direction,
            'needed' => [] !== $pending,
            'migrations' => $pending,
            'lock' => $this->probeLockInfo(),
            'issues' => $this->collectProbeIssues($migrations, $direction, $force, $repair),
        ];
    }

    /**
     * @return array<int,array{id:string,name:string,class:string,checksum:string,applied:bool,applied_checksum:?string,checksum_matches:?bool,error:?string}>
     */
    public function listMigrations(): array
    {
        $migrations = $this->loadMigrations();
        if (empty($migrations)) {
            return [];
        }

        $this->ensureVersionTable();
        $this->ensureLockTable();
        $applied = $this->getAppliedRowsByVersion();

        return array_map(static function (array $migration) use ($applied): array {
            $version = (string) $migration['id'];
            $appliedRow = $applied[$version] ?? null;
            $appliedChecksum = is_array($appliedRow) ? (string) ($appliedRow['checksum'] ?? '') : '';

            $migration['applied'] = null !== $appliedRow;
            $migration['applied_checksum'] = null !== $appliedRow ? $appliedChecksum : null;
            $migration['checksum_matches'] = null !== $appliedRow
                ? '' !== $appliedChecksum && hash_equals((string) $migration['checksum'], $appliedChecksum)
                : null;
            $migration['error'] = null;

            if (null !== $appliedRow && '' === $appliedChecksum) {
                $migration['error'] = 'Applied migration has no stored checksum.';
            } elseif (null !== $appliedRow && false === $migration['checksum_matches']) {
                $migration['error'] = 'Stored checksum does not match migration file.';
            }

            return $migration;
        }, $migrations);
    }

    /**
     * @return array{table:string,locked:bool,holder:?string,acquired_at:?int}
     */
    public function lockInfo(): array
    {
        $this->ensureLockTable();

        $stmt = $this->pdo->prepare("SELECT holder, acquired_at FROM {$this->lockTable} WHERE lock_key = :lock_key LIMIT 1");
        $stmt->execute(['lock_key' => self::LOCK_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'table' => $this->lockTable,
                'locked' => false,
                'holder' => null,
                'acquired_at' => null,
            ];
        }

        $acquiredAt = null;
        if (isset($row['acquired_at'])) {
            $value = (string) $row['acquired_at'];
            if (ctype_digit($value)) {
                $acquiredAt = (int) $value;
            }
        }

        return [
            'table' => $this->lockTable,
            'locked' => true,
            'holder' => isset($row['holder']) ? (string) $row['holder'] : null,
            'acquired_at' => $acquiredAt,
        ];
    }

    /**
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    public function markAppliedUpTo(
        #[\SensitiveParameter]
        string $token,
        bool $dryRun = false,
        bool $force = false,
        bool $repair = false,
    ): array {
        $token = trim($token);
        if ('' === $token) {
            throw new RuntimeException('Migration token is required.');
        }

        $migrations = $this->loadMigrations();
        if (empty($migrations)) {
            return [];
        }

        $this->ensureVersionTable();
        $this->ensureLockTable();

        $matches = $this->findMatches($migrations, $token);
        if (0 === count($matches)) {
            throw new RuntimeException('No matching migration found.');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Multiple migrations match the token. Use a more specific token.');
        }

        $targetIndex = $matches[0];
        $targets = array_slice($migrations, 0, $targetIndex + 1);

        if ($dryRun && !$repair) {
            return $targets;
        }

        $runner = function () use ($migrations, $targets, $repair, $force): void {
            $this->validateAppliedState($migrations, $force, $repair);

            $applied = array_flip($this->getAppliedVersions());
            $this->runInTransaction(function () use ($targets, $applied): void {
                foreach ($targets as $migration) {
                    $version = (string) $migration['id'];
                    if (isset($applied[$version])) {
                        continue;
                    }

                    $this->insertVersion(
                        $version,
                        (string) $migration['name'],
                        (string) $migration['checksum'],
                    );
                }
            });
        };

        $this->withLock($runner, $force);
        return $targets;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    private function applyUp(array $migrations, bool $dryRun, int $steps, bool $force): array
    {
        if (!$force) {
            $this->assertNoGaps();
        }

        $pending = $this->selectUpMigrations($migrations, $this->getAppliedVersions(), $steps);

        $ran = [];
        foreach ($pending as $migration) {
            $ran[] = $migration;

            if ($dryRun) {
                continue;
            }

            $this->runInTransaction(function () use ($migration): void {
                $this->runMigration((string) $migration['class'], 'up');
                $this->insertVersion(
                    (string) $migration['id'],
                    (string) $migration['name'],
                    (string) $migration['checksum'],
                );
            });
        }

        return $ran;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    private function applyDown(array $migrations, bool $dryRun, int $steps): array
    {
        $applied = $this->getAppliedVersions();
        if (empty($applied)) {
            return [];
        }

        $byId = [];
        foreach ($migrations as $migration) {
            $byId[(string) $migration['id']] = $migration;
        }

        $targets = array_slice($applied, 0, $steps);
        $ran = [];

        foreach ($targets as $version) {
            if (!isset($byId[$version])) {
                throw new MigrationMissingException(sprintf('Applied migration version %s is missing from source files.', $version));
            }

            $migration = $byId[$version];
            $ran[] = $migration;

            if ($dryRun) {
                continue;
            }

            $this->runInTransaction(function () use ($migration, $version): void {
                $this->runMigration((string) $migration['class'], 'down');
                $this->deleteVersion($version);
            });
        }

        return $ran;
    }

    private function assertNoGaps(): void
    {
        $migrations = $this->loadMigrations();
        if (empty($migrations)) {
            return;
        }

        $this->ensureVersionTable();
        $issue = $this->detectGapIssue($migrations, $this->getAppliedVersions());
        if (null !== $issue) {
            throw new MigrationOrderException($issue);
        }
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Only up/down migration path available.');
        }

        return $direction;
    }

    private function runMigration(string $class, string $direction): void
    {
        $instance = new $class();
        if (!$instance instanceof SchemaBlueprintMigration) {
            throw new RuntimeException(sprintf('Migration %s must extend %s.', $class, SchemaBlueprintMigration::class));
        }

        new SchemaBlueprintRunner($this->pdo)->run($instance, $direction);
    }

    /**
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    private function loadMigrations(): array
    {
        $definitions = $this->registry->all();
        if (empty($definitions)) {
            return [];
        }

        $migrations = [];
        foreach ($definitions as $definition) {
            $migrations[] = [
                'id' => $definition->id,
                'name' => $definition->name,
                'class' => $definition->class,
                'checksum' => $this->checksumForClass($definition->class),
            ];
        }

        return $migrations;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @return array<int,int>
     */
    private function findMatches(array $migrations, #[\SensitiveParameter] string $token): array
    {
        $token = strtolower($token);
        $matches = [];
        foreach ($migrations as $index => $migration) {
            $id = strtolower((string) $migration['id']);
            $name = strtolower((string) $migration['name']);

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

    private function runInTransaction(callable $callback): void
    {
        $started = $this->pdo->beginTransaction();
        try {
            $callback();
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function ensureVersionTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ('mysql' === $driver) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->versionTable} (
                    id INT NOT NULL AUTO_INCREMENT,
                    version VARCHAR(32) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    checksum VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_version (version)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        if ('pgsql' === $driver) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->versionTable} (
                    id BIGSERIAL PRIMARY KEY,
                    version VARCHAR(32) NOT NULL UNIQUE,
                    name VARCHAR(255) NOT NULL,
                    checksum VARCHAR(64) NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                )");
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->versionTable} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                checksum TEXT NULL,
                created_at INTEGER NOT NULL
            )");
    }

    private function ensureLockTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ('mysql' === $driver) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->lockTable} (
                    lock_key VARCHAR(64) NOT NULL,
                    holder VARCHAR(128) NOT NULL,
                    acquired_at BIGINT NOT NULL,
                    PRIMARY KEY (lock_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        if ('pgsql' === $driver) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->lockTable} (
                    lock_key VARCHAR(64) PRIMARY KEY,
                    holder VARCHAR(128) NOT NULL,
                    acquired_at BIGINT NOT NULL
                )");
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->lockTable} (
                lock_key TEXT PRIMARY KEY,
                holder TEXT NOT NULL,
                acquired_at INTEGER NOT NULL
            )");
    }

    private function insertVersion(string $version, string $name, string $checksum): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ('mysql' === $driver || 'pgsql' === $driver) {
            $stmt = $this->pdo->prepare("INSERT INTO {$this->versionTable} (version, name, checksum)
                 VALUES (:version, :name, :checksum)");
            $stmt->execute([
                'version' => $version,
                'name' => $name,
                'checksum' => $checksum,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare("INSERT INTO {$this->versionTable} (version, name, checksum, created_at)
             VALUES (:version, :name, :checksum, :created_at)");
        $stmt->execute([
            'version' => $version,
            'name' => $name,
            'checksum' => $checksum,
            'created_at' => time(),
        ]);
    }

    private function deleteVersion(string $version): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->versionTable} WHERE version = :version");
        $stmt->execute(['version' => $version]);
    }

    private function updateVersionChecksum(string $version, string $checksum): void
    {
        $stmt = $this->pdo->prepare("UPDATE {$this->versionTable} SET checksum = :checksum WHERE version = :version");
        $stmt->execute([
            'version' => $version,
            'checksum' => $checksum,
        ]);
    }

    private function getCurrentVersion(): string
    {
        return $this->currentVersionFromVersions($this->getAppliedVersions());
    }

    /**
     * @param array<int,string> $versions
     */
    private function currentVersionFromVersions(array $versions): string
    {
        if (empty($versions)) {
            return '';
        }

        $max = $versions[0];
        foreach ($versions as $version) {
            if ($this->compareIds($version, $max) <= 0) {
                continue;
            }
            $max = $version;
        }

        return $max;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @param array<int,string> $appliedVersions
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    private function selectUpMigrations(array $migrations, array $appliedVersions, int $steps): array
    {
        $current = $this->currentVersionFromVersions($appliedVersions);
        $pending = array_values(array_filter($migrations, fn(array $m): bool => $this->compareIds((string) $m['id'], $current) > 0));

        if ($steps > 0) {
            $pending = array_slice($pending, 0, $steps);
        }

        return $pending;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @param array<int,string> $appliedVersions
     * @return array<int,array{id:string,name:string,class:string,checksum:string}>
     */
    private function selectDownMigrations(array $migrations, array $appliedVersions, int $steps): array
    {
        if (empty($appliedVersions)) {
            return [];
        }

        $targets = array_slice($appliedVersions, 0, $steps > 0 ? $steps : 1);
        $byId = [];
        foreach ($migrations as $migration) {
            $byId[(string) $migration['id']] = $migration;
        }

        $selected = [];
        foreach ($targets as $version) {
            if (!isset($byId[$version])) {
                continue;
            }

            $selected[] = $byId[$version];
        }

        return $selected;
    }

    /**
     * @return array<int,string>
     */
    private function getAppliedVersions(): array
    {
        $stmt = $this->pdo->query("SELECT version FROM {$this->versionTable}");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $versions = array_map(strval(...), $rows ?: []);
        usort($versions, fn(string $a, string $b): int => $this->compareIds($b, $a));

        return $versions;
    }

    /**
     * @return array<string,array{version:string,name:string,checksum:string}>
     */
    private function getAppliedRowsByVersion(): array
    {
        $stmt = $this->pdo->query("SELECT version, name, checksum FROM {$this->versionTable}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $applied = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['version'])) {
                continue;
            }

            $version = (string) $row['version'];
            $applied[$version] = [
                'version' => $version,
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'checksum' => isset($row['checksum']) ? (string) $row['checksum'] : '',
            ];
        }

        return $applied;
    }

    /**
     * @return array<int,string>
     */
    private function probeAppliedVersions(): array
    {
        if (!$this->tableExists($this->versionTable)) {
            return [];
        }

        return $this->getAppliedVersions();
    }

    /**
     * @return array<string,array{version:string,name:string,checksum:string}>
     */
    private function probeAppliedRowsByVersion(): array
    {
        if (!$this->tableExists($this->versionTable)) {
            return [];
        }

        return $this->getAppliedRowsByVersion();
    }

    /**
     * @return array{table:string,locked:bool,holder:?string,acquired_at:?int}
     */
    private function probeLockInfo(): array
    {
        if (!$this->tableExists($this->lockTable)) {
            return [
                'table' => $this->lockTable,
                'locked' => false,
                'holder' => null,
                'acquired_at' => null,
            ];
        }

        $stmt = $this->pdo->prepare("SELECT holder, acquired_at FROM {$this->lockTable} WHERE lock_key = :lock_key LIMIT 1");
        $stmt->execute(['lock_key' => self::LOCK_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'table' => $this->lockTable,
                'locked' => false,
                'holder' => null,
                'acquired_at' => null,
            ];
        }

        $acquiredAt = null;
        if (isset($row['acquired_at'])) {
            $value = (string) $row['acquired_at'];
            if (ctype_digit($value)) {
                $acquiredAt = (int) $value;
            }
        }

        return [
            'table' => $this->lockTable,
            'locked' => true,
            'holder' => isset($row['holder']) ? (string) $row['holder'] : null,
            'acquired_at' => $acquiredAt,
        ];
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     */
    private function validateAppliedState(array $migrations, bool $force, bool $repair): void
    {
        if ($force && !$repair) {
            return;
        }

        $applied = $this->getAppliedRowsByVersion();
        if (empty($applied)) {
            return;
        }

        $known = [];
        foreach ($migrations as $migration) {
            $known[(string) $migration['id']] = $migration;
        }

        foreach ($applied as $version => $row) {
            if (!isset($known[$version])) {
                throw new MigrationMissingException(
                    sprintf('Applied migration version %s is missing from source files.', $version),
                );
            }

            $storedChecksum = (string) ($row['checksum'] ?? '');
            $currentChecksum = (string) $known[$version]['checksum'];

            if ('' !== $storedChecksum && hash_equals($storedChecksum, $currentChecksum)) {
                continue;
            }

            if ($repair) {
                $this->updateVersionChecksum($version, $currentChecksum);
                continue;
            }

            if ('' === $storedChecksum) {
                throw new MigrationChecksumMismatchException(
                    sprintf(
                        'Applied migration version %s has no checksum recorded. Re-run with repair to persist checksums.',
                        $version,
                    ),
                );
            }

            throw new MigrationChecksumMismatchException(
                sprintf(
                    'Checksum mismatch for migration version %s. Stored: %s, current: %s.',
                    $version,
                    $storedChecksum,
                    $currentChecksum,
                ),
            );
        }
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @return array<int,string>
     */
    private function collectProbeIssues(array $migrations, string $direction, bool $force, bool $repair): array
    {
        if ($force && !$repair) {
            return [];
        }

        $issues = $this->collectAppliedStateIssues($migrations, $repair);
        if ('up' === $direction) {
            $gapIssue = $this->detectGapIssue($migrations, $this->probeAppliedVersions());
            if (null !== $gapIssue) {
                $issues[] = $gapIssue;
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @return array<int,string>
     */
    private function collectAppliedStateIssues(array $migrations, bool $repair): array
    {
        $applied = $this->probeAppliedRowsByVersion();
        if (empty($applied)) {
            return [];
        }

        $known = [];
        foreach ($migrations as $migration) {
            $known[(string) $migration['id']] = $migration;
        }

        $issues = [];
        foreach ($applied as $version => $row) {
            if (!isset($known[$version])) {
                $issues[] = sprintf('Applied migration version %s is missing from source files.', $version);
                continue;
            }

            $storedChecksum = (string) ($row['checksum'] ?? '');
            $currentChecksum = (string) $known[$version]['checksum'];

            if ('' !== $storedChecksum && hash_equals($storedChecksum, $currentChecksum)) {
                continue;
            }

            if ($repair) {
                continue;
            }

            if ('' === $storedChecksum) {
                $issues[] = sprintf(
                    'Applied migration version %s has no checksum recorded. Re-run with repair to persist checksums.',
                    $version,
                );
                continue;
            }

            $issues[] = sprintf(
                'Checksum mismatch for migration version %s. Stored: %s, current: %s.',
                $version,
                $storedChecksum,
                $currentChecksum,
            );
        }

        return $issues;
    }

    /**
     * @param array<int,array{id:string,name:string,class:string,checksum:string}> $migrations
     * @param array<int,string> $appliedVersions
     */
    private function detectGapIssue(array $migrations, array $appliedVersions): ?string
    {
        if (empty($migrations) || empty($appliedVersions)) {
            return null;
        }

        $applied = array_flip($appliedVersions);
        $seenUnapplied = false;

        foreach ($migrations as $migration) {
            $version = (string) $migration['id'];
            $isApplied = isset($applied[$version]);
            if (!$isApplied) {
                $seenUnapplied = true;
                continue;
            }

            if ($seenUnapplied) {
                return sprintf('Out-of-order migration state detected at version %s. Resolve ordering before continuing.', $version);
            }
        }

        return null;
    }

    private function tableExists(string $table): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => $this->tableExistsMysql($table),
            'pgsql' => $this->tableExistsPostgres($table),
            default => $this->tableExistsSqlite($table),
        };
    }

    private function tableExistsMysql(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
        );
        $stmt->execute(['table' => $table]);

        return false !== $stmt->fetchColumn();
    }

    private function tableExistsPostgres(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table LIMIT 1',
        );
        $stmt->execute(['table' => $table]);

        return false !== $stmt->fetchColumn();
    }

    private function tableExistsSqlite(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(['table' => $table]);

        return false !== $stmt->fetchColumn();
    }

    private function checksumForClass(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $path = $reflection->getFileName();
        if (!is_string($path) || '' === $path) {
            throw new RuntimeException(sprintf('Unable to resolve file path for migration class %s.', $class));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read migration file %s.', $path));
        }

        return hash('sha256', $contents);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withLock(callable $callback, bool $force)
    {
        $holder = sprintf('%s:%d', gethostname() ?: 'host', getmypid() ?: 0);
        if (!$this->acquireLock($holder, $force)) {
            throw new MigrationLockException('Migration execution lock is already held by another runner.');
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($holder);
        }
    }

    private function acquireLock(string $holder, bool $force): bool
    {
        if ($force) {
            $this->clearLock();
        }

        $stmt = $this->pdo->prepare("INSERT INTO {$this->lockTable} (lock_key, holder, acquired_at)
             VALUES (:lock_key, :holder, :acquired_at)");

        try {
            return $stmt->execute([
                'lock_key' => self::LOCK_KEY,
                'holder' => $holder,
                'acquired_at' => time(),
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseLock(string $holder): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->lockTable} WHERE lock_key = :lock_key AND holder = :holder");
        $stmt->execute([
            'lock_key' => self::LOCK_KEY,
            'holder' => $holder,
        ]);
    }

    private function clearLock(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->lockTable} WHERE lock_key = :lock_key");
        $stmt->execute(['lock_key' => self::LOCK_KEY]);
    }

    private function compareIds(string $a, string $b): int
    {
        $aIsNumeric = ctype_digit($a);
        $bIsNumeric = ctype_digit($b);

        if ($aIsNumeric && $bIsNumeric) {
            $lenDiff = strlen($a) <=> strlen($b);
            if (0 !== $lenDiff) {
                return $lenDiff;
            }
        }

        return strcmp($a, $b);
    }
}
