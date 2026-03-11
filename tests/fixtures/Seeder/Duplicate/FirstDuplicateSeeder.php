<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\Duplicate;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'dup_name')]
final class FirstDuplicateSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO seed_items (label) VALUES ('dup-1')");
    }
}
