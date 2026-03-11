<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\Graph;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'alpha')]
final class AlphaSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO seed_items (label) VALUES ('alpha')");
    }
}
