<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\RunModes;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'always_mode', mode: 'always', tags: ['extra'], groups: ['defaults'])]
final class AlwaysSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO seed_items (label) VALUES ('always')");
    }
}
