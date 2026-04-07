<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\IndexDefinition;
use Closure;

final class SchemaIntrospectOptions
{
    /**
     * @var array<int,string>
     */
    public readonly array $ignoreTables;

    private readonly ?Closure $ignoreIndex;

    /**
     * @param array<int,string> $ignoreTables
     * @param ?callable(string,IndexDefinition):bool $ignoreIndex
     */
    public function __construct(array $ignoreTables = [], ?callable $ignoreIndex = null)
    {
        $this->ignoreTables = array_values(array_filter(
            array_map(static fn(mixed $table): string => trim((string) $table), $ignoreTables),
            static fn(string $table): bool => '' !== $table,
        ));
        $this->ignoreIndex = null === $ignoreIndex ? null : Closure::fromCallable($ignoreIndex);
    }

    public function shouldIgnoreTable(string $table): bool
    {
        return in_array($table, $this->ignoreTables, true);
    }

    public function shouldIgnoreIndex(string $table, IndexDefinition $index): bool
    {
        if (null === $this->ignoreIndex) {
            return false;
        }

        return (bool) ($this->ignoreIndex)($table, $index);
    }
}
