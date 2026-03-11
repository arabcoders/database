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
            && $this->where === $other->where
            && $this->expression === $other->expression
            && $this->columns === $other->columns
        );
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
