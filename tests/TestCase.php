<?php

declare(strict_types=1);

namespace tests;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?string $runTempPath = null;

    /**
     * @var list<string>
     */
    private array $tempPaths = [];

    private static bool $tempRuntimeRegistered = false;

    private static function ensureTempRuntime(): void
    {
        if (null !== self::$runTempPath) {
            return;
        }

        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'database-tests';
        self::ensureDirectoryExists($root);

        self::$runTempPath = $root . DIRECTORY_SEPARATOR . 'run-' . self::uniqueToken();
        self::ensureDirectoryExists(self::$runTempPath);

        if (!self::$tempRuntimeRegistered) {
            register_shutdown_function(static function (): void {
                self::cleanupRunTempRuntime();
            });
            self::$tempRuntimeRegistered = true;
        }
    }

    private static function cleanupRunTempRuntime(): void
    {
        if (null === self::$runTempPath) {
            return;
        }

        $path = self::$runTempPath;
        self::$runTempPath = null;

        self::forceRemovePath($path);
    }

    /**
     * @template T of PDO
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function memoryPdo(string $class = PDO::class): PDO
    {
        /** @var T $pdo */
        $pdo = new $class('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    protected function tempDir(string $prefix = 'test'): string
    {
        self::ensureTempRuntime();

        $path = self::$runTempPath . DIRECTORY_SEPARATOR . self::normalizePathToken($prefix) . '-' . self::uniqueToken();

        self::ensureDirectoryExists($path);
        $this->tempPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_reverse($this->tempPaths) as $path) {
            self::forceRemovePath($path);
        }

        $this->tempPaths = [];
    }

    private static function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $directory));
        }
    }

    private static function normalizePathToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return '' === $normalized ? 'test' : $normalized;
    }

    private static function uniqueToken(): string
    {
        return str_replace('.', '', uniqid('', true));
    }

    private static function forceRemovePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (!is_dir($path)) {
            unlink($path);
            return;
        }

        self::forceRemoveDirectory($path);
    }

    private static function forceRemoveDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
