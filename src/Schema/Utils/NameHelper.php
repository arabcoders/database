<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Utils;

final class NameHelper
{
    /**
     * @param array<int,string> $columns
     */
    public static function indexName(string $table, array $columns, bool $unique, string $type): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        $type = strtolower($type);

        if ('fulltext' === $type) {
            $prefix = 'ft';
        } elseif ('spatial' === $type) {
            $prefix = 'sp';
        }

        $name = $prefix . '_' . $table . '_' . implode('_', $columns);

        return self::shorten($name);
    }

    /**
     * @param array<int,string> $columns
     */
    public static function foreignKeyName(string $table, array $columns, string $referenceTable): string
    {
        $name = 'fk_' . $table . '_' . implode('_', $columns) . '_' . $referenceTable;

        return self::shorten($name);
    }

    private static function shorten(string $name, int $limit = 64): string
    {
        if (strlen($name) <= $limit) {
            return $name;
        }

        $hash = substr(sha1($name), 0, 8);

        return substr($name, 0, $limit - 9) . '_' . $hash;
    }
}
