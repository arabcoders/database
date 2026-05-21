<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

final readonly class IndexDefinition
{
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
        public string $type = 'index',
        public array $algorithm = [],
        public array $lengths = [],
        public ?string $where = null,
        public ?string $expression = null,
    ) {}

    /**
     * Determine whether this definition is semantically equivalent to another definition.
     * @param self $other Other.
     * @return bool
     */

    public function equals(self $other): bool
    {
        return (
            $this->unique === $other->unique
            && strtolower($this->type) === strtolower($other->type)
            && $this->normalizeDriverValue($this->algorithm) === $this->normalizeDriverValue($other->algorithm)
            && $this->normalizeLengths($this->lengths) === $this->normalizeLengths($other->lengths)
            && $this->where === $other->where
            && $this->expression === $other->expression
            && $this->columns === $other->columns
        );
    }

    /**
     * @param array<string|int,mixed> $lengths
     * @return array<string,int>
     */
    private function normalizeLengths(array $lengths): array
    {
        $normalized = [];
        foreach ($lengths as $column => $length) {
            $name = trim((string) $column);
            if ('' === $name) {
                continue;
            }

            $value = (int) $length;
            if ($value <= 0) {
                continue;
            }

            $normalized[$name] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeDriverValue(array $value): ?string
    {
        if ([] === $value) {
            return null;
        }

        if (array_key_exists('default', $value)) {
            $defaultValue = $value['default'];
            return is_string($defaultValue) ? $defaultValue : null;
        }

        return null;
    }
}
