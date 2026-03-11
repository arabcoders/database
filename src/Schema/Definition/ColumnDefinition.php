<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unsigned = false,
        public bool $nullable = false,
        public bool $autoIncrement = false,
        public bool $hasDefault = false,
        public mixed $default = null,
        public bool $defaultIsExpression = false,
        public array $charset = [],
        public array $collation = [],
        public ?string $comment = null,
        public ?string $onUpdate = null,
        public ?string $previousName = null,
        public ?string $propertyName = null,
        public ?string $typeName = null,
        public ?array $allowed = null,
        public bool $check = false,
        public ?string $checkExpression = null,
        public bool $generated = false,
        public ?string $generatedExpression = null,
        public ?bool $generatedStored = null,
    ) {}

    /**
     * Determine whether this definition is semantically equivalent to another definition.
     * @param self $other Other.
     * @return bool
     */

    public function equals(self $other): bool
    {
        if ($this->type !== $other->type) {
            return false;
        }

        if ($this->type === ColumnType::Custom) {
            $left = strtolower((string) ($this->typeName ?? ''));
            $right = strtolower((string) ($other->typeName ?? ''));
            if ($left !== $right) {
                return false;
            }
        }

        if ($this->length !== $other->length) {
            return false;
        }

        if ($this->precision !== $other->precision || $this->scale !== $other->scale) {
            return false;
        }

        if ($this->unsigned !== $other->unsigned) {
            return false;
        }

        if ($this->nullable !== $other->nullable) {
            return false;
        }

        if ($this->autoIncrement !== $other->autoIncrement) {
            return false;
        }

        if ($this->hasDefault !== $other->hasDefault) {
            return false;
        }

        if ($this->hasDefault) {
            if ($this->defaultIsExpression !== $other->defaultIsExpression) {
                return false;
            }

            if ($this->normalizeDefault($this->default) !== $this->normalizeDefault($other->default)) {
                return false;
            }
        }

        if ($this->normalizeDriverValue($this->charset) !== $this->normalizeDriverValue($other->charset)) {
            return false;
        }

        if ($this->normalizeDriverValue($this->collation) !== $this->normalizeDriverValue($other->collation)) {
            return false;
        }

        if ($this->comment !== $other->comment) {
            return false;
        }

        if ($this->onUpdate !== $other->onUpdate) {
            return false;
        }

        if ($this->allowed !== $other->allowed) {
            return false;
        }

        if ($this->check !== $other->check) {
            return false;
        }

        if ($this->checkExpression !== $other->checkExpression) {
            return false;
        }

        if ($this->generated !== $other->generated) {
            return false;
        }

        if ($this->generatedExpression !== $other->generatedExpression) {
            return false;
        }

        if ($this->generatedStored !== $other->generatedStored) {
            return false;
        }

        return true;
    }

    private function normalizeDefault(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value, "'\"");
            return strtolower($normalized);
        }

        return strtolower(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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
