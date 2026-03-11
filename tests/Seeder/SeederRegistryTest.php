<?php

declare(strict_types=1);

namespace tests\Seeder;

use arabcoders\database\Seeder\SeederRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeederRegistryTest extends TestCase
{
    public function testRegistryRejectsDuplicateSeederNames(): void
    {
        $registry = new SeederRegistry([$this->fixturePath('Duplicate')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate seeder name found: dup_name');

        $registry->all();
    }

    private function fixturePath(string $suffix): string
    {
        return TESTS_PATH . '/fixtures/Seeder/' . $suffix;
    }
}
