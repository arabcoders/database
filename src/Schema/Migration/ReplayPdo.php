<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use PDO;
use PDOStatement;
use RuntimeException;

/** PDO-shaped guard used only by legacy migration replay. */
final class ReplayPdo extends PDO
{
    public function __construct() {}

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return null;
    }

    public function exec(string $statement): int|false
    {
        throw new RuntimeException('Legacy migration replay does not permit database statements.');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new RuntimeException('Legacy migration replay does not permit database statements.');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new RuntimeException('Legacy migration replay does not permit database statements.');
    }

    public function beginTransaction(): bool
    {
        throw new RuntimeException('Legacy migration replay does not permit transactions.');
    }

    public function inTransaction(): bool
    {
        return false;
    }
}
