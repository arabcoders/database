<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\Transactions;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;

#[Seeder(name: 'tx_first')]
final class FirstTxSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO tx_items (label) VALUES ('first')");
    }
}
