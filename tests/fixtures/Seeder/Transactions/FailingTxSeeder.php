<?php

declare(strict_types=1);

namespace tests\fixtures\Seeder\Transactions;

use arabcoders\database\Attributes\Seeder;
use arabcoders\database\Connection;
use arabcoders\database\Seeder\SeederRunner;
use RuntimeException;

#[Seeder(name: 'tx_fail', dependsOn: ['tx_first'])]
final class FailingTxSeeder extends SeederRunner
{
    public function __invoke(Connection $connection): void
    {
        $connection->execRaw("INSERT INTO tx_items (label) VALUES ('second')");
        throw new RuntimeException('intentional seeder failure');
    }
}
