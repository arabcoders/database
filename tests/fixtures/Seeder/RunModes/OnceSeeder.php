<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\RunModes;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunMode;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'once_mode', mode: SeederRunMode::ONCE, tags: ['core'], groups: ['defaults'])]
final class OnceSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO seed_items (label) VALUES ('once')");
    }
}
