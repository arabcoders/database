<?php

declare(strict_types=1);

namespace tests\fixtures;

use PDO;
use PDOStatement;

final class FailingPdo extends PDO
{
    public function prepare(string $statement, array $options = []): PDOStatement|false
    {
        return false;
    }
}
