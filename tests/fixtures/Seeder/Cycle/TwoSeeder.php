<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\Cycle;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'two', dependsOn: ['one'])]
final class TwoSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO seed_items (label) VALUES ('two')");
    }
}
