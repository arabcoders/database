<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use RuntimeException;

final class SeederRunMode
{
    public const string AUTO = 'auto';
    public const string ONCE = 'once';
    public const string ALWAYS = 'always';
    public const string REBUILD = 'rebuild';

    public static function normalize(string $mode, bool $allowAuto = true): string
    {
        $normalized = strtolower(trim($mode));
        $allowed = [self::ONCE, self::ALWAYS, self::REBUILD];
        if ($allowAuto) {
            $allowed[] = self::AUTO;
        }

        if (!in_array($normalized, $allowed, true)) {
            throw new RuntimeException(sprintf('Unsupported seeder run mode: %s', $mode));
        }

        return $normalized;
    }
}
