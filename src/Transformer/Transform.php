<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

use Attribute;
use ReflectionClass;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Transform
{
    /**
     * @var array Arguments to pass to the transformer constructor.
     */
    public array $args;

    public function __construct(
        public string $class,
        mixed ...$args,
    ) {
        $this->args = $args;
    }

    public function makeCallable(?ReflectionProperty $property = null): callable
    {
        $class = new $this->class(...$this->resolveArgs($property));
        return $class(...);
    }

    /**
     * @return array<string|int,mixed>
     */
    private function resolveArgs(?ReflectionProperty $property): array
    {
        $args = $this->args;
        if (null === $property) {
            return $args;
        }

        $type = $property->getType();
        if (null === $type || !$type->allowsNull()) {
            return $args;
        }

        $reflection = new ReflectionClass($this->class);
        $constructor = $reflection->getConstructor();
        if (null === $constructor) {
            return $args;
        }

        $nullablePosition = null;
        foreach ($constructor->getParameters() as $index => $parameter) {
            if ('nullable' !== $parameter->getName()) {
                continue;
            }

            $nullablePosition = $index;
            break;
        }

        if (null === $nullablePosition) {
            return $args;
        }

        if (array_key_exists('nullable', $args) || array_key_exists($nullablePosition, $args)) {
            return $args;
        }

        $args['nullable'] = true;

        return $args;
    }
}
