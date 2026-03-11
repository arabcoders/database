<?php

declare(strict_types=1);

namespace arabcoders\database\Validator;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Validate
{
    /**
     * @var array Arguments to pass to the validator constructor.
     */
    public array $args;

    public function __construct(
        public string $class,
        public ValidationType|array $type = [],
        mixed ...$args,
    ) {
        $this->args = $args;
    }

    public function makeCallable(): callable
    {
        $class = new $this->class(...$this->args);
        return $class(...);
    }

    /**
     * @return array<int,ValidationType>
     */
    public function resolvedTypes(): array
    {
        return $this->normalizeType($this->type);
    }

    /**
     * @return array<int,ValidationType>
     */
    private function normalizeType(ValidationType|array $type): array
    {
        if ($type instanceof ValidationType) {
            return [$type];
        }

        if ([] === $type) {
            return ValidationType::cases();
        }

        $normalized = [];
        foreach ($type as $value) {
            if (!$value instanceof ValidationType) {
                throw new InvalidArgumentException('Validate type array must contain only ValidationType values.');
            }

            $normalized[$value->name] = $value;
        }

        return array_values($normalized);
    }
}
