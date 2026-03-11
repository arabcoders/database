<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Attributes\Schema\Table;
use ReflectionClass;
use RuntimeException;

final class TableResolver
{
    /**
     * Resolve a table identifier from a raw table name or model class name.
     *
     * @param string $table Table name or FQCN.
     * @return string
     * @throws RuntimeException If the table input is empty.
     */
    public static function resolve(string $table): string
    {
        if ('' === trim($table)) {
            throw new RuntimeException('Table name is required.');
        }

        if (!str_contains($table, '\\')) {
            return $table;
        }

        if (!class_exists($table)) {
            return $table;
        }

        $ref = new ReflectionClass($table);
        $attributes = $ref->getAttributes(Table::class);
        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            if (is_string($attribute->name) && '' !== trim($attribute->name)) {
                return $attribute->name;
            }
        }

        if ($ref->hasConstant('TABLE_NAME')) {
            $name = $ref->getConstant('TABLE_NAME');
            if (is_string($name) && '' !== trim($name)) {
                return $name;
            }
        }

        return $table;
    }
}
