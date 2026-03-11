<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use RuntimeException;

final class SeederTransactionMode
{
    public const string NONE = 'none';
    public const string PER_SEEDER = 'per-seeder';
    public const string PER_RUN = 'per-run';

    public static function normalize(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        $allowed = [self::NONE, self::PER_SEEDER, self::PER_RUN];
        if (!in_array($normalized, $allowed, true)) {
            throw new RuntimeException(sprintf('Unsupported seeder transaction mode: %s', $mode));
        }

        return $normalized;
    }
}
