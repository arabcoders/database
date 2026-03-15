<?php

declare(strict_types=1);

namespace arabcoders\database\Model;

use arabcoders\database\Attributes\Differ;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Transformer\Transform;
use arabcoders\database\Transformer\TransformType;
use DateTimeInterface;
use JsonSerializable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Stringable;

abstract class BaseModel implements JsonSerializable, ProvidesDiff, TracksChanges
{
    /**
     * @var array<string,mixed>
     */
    protected array $_original = [];

    /**
     * @var array<class-string, array<int,string>>
     */
    private static array $fieldCache = [];

    /**
     * @var array<class-string, array<string,callable>>
     */
    private static array $differCache = [];

    /**
     * @var array<class-string, array<string,callable>>
     */
    private static array $transformerCache = [];

    /**
     * @var array<int,string>
     */
    protected array $ignored = [];

    /**
     * @var array<int,string>
     */
    protected array $protected = [];

    public function __construct(array $data = [], bool $isCustom = false, array $options = [])
    {
        if (count($data) > 0) {
            $this->hydrate($data);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function fields(): array
    {
        $class = static::class;
        if (isset(self::$fieldCache[$class])) {
            return self::$fieldCache[$class];
        }

        self::$fieldCache[$class] = static::resolveFields();

        return self::$fieldCache[$class];
    }

    public static function primaryKey(): string
    {
        return 'id';
    }

    public static function fromRow(array $row): static
    {
        $entity = new static(options: ['from_local' => true]);
        $entity->hydrate($row, true);

        return $entity;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function hydrate(array $row, bool $markClean = false): void
    {
        foreach ($row as $key => $value) {
            if (!property_exists($this, $key)) {
                continue;
            }

            $this->{$key} = $this->decodeValue($key, $value);
        }

        if ($markClean) {
            $this->markClean();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(bool $encode = false, bool $omit = true): array
    {
        $data = [];
        $protected = $omit ? array_fill_keys($this->protected, true) : [];
        foreach (static::fields() as $field) {
            if (isset($protected[$field])) {
                continue;
            }

            if (!property_exists($this, $field)) {
                continue;
            }

            $value = $this->{$field} ?? null;
            $data[$field] = $encode ? $this->encodeValue($field, $value) : $value;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function diff(bool $deep = false, array $columns = []): array
    {
        $differs = $this->differsForClass();
        $fields = !empty($columns) ? array_fill_keys($columns, true) : array_fill_keys(static::fields(), true);
        $ignored = array_fill_keys($this->ignored, true);
        $changes = [];
        foreach (static::fields() as $field) {
            if (!array_key_exists($field, $fields) || isset($ignored[$field])) {
                continue;
            }

            if (!property_exists($this, $field)) {
                continue;
            }

            $current = $this->{$field} ?? null;
            $previous = $this->_original[$field] ?? null;

            if (isset($differs[$field]) && $this->invokeDiffer($differs[$field], $previous, $current, $field)) {
                continue;
            }

            if ($current instanceof DateTimeInterface && $previous instanceof DateTimeInterface) {
                if ($current->getTimestamp() === $previous->getTimestamp()) {
                    continue;
                }
            }

            if ($current instanceof Stringable) {
                $current = (string) $current;
            }

            if ($previous instanceof Stringable) {
                $previous = (string) $previous;
            }

            if ($current !== $previous) {
                $changes[$field] = $deep ? ['old' => $previous, 'new' => $current] : $current;
            }
        }

        return $changes;
    }

    public function markClean(): void
    {
        $this->_original = $this->toArray(omit: false);
    }

    /**
     * Apply values from another object onto mapped model properties.
     * @param object $item Item.
     * @return static
     */

    public function apply(object $item, array $columns = []): static
    {
        $fields = !empty($columns) ? array_fill_keys($columns, true) : array_fill_keys(static::fields(), true);
        $ignored = array_fill_keys($this->ignored, true);
        $primaryKey = static::primaryKey();

        foreach (get_object_vars($item) as $key => $value) {
            if (!property_exists($this, $key)) {
                continue;
            }

            if ($key === $primaryKey) {
                continue;
            }

            if (!array_key_exists($key, $fields) || isset($ignored[$key])) {
                continue;
            }

            $this->{$key} = $value;
        }

        return $this;
    }

    public function getPrimaryId(): mixed
    {
        $key = static::primaryKey();
        return property_exists($this, $key) ? $this->{$key} ?? null : null;
    }

    /**
     * @return array<int,string>
     */
    protected static function resolveFields(): array
    {
        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $all = [];
        $columns = [];

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $all[] = $name;

            if (!empty($property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF))) {
                $columns[] = $name;
            }
        }

        return !empty($columns) ? $columns : $all;
    }

    protected function encodeValue(string $field, mixed $value): mixed
    {
        if (null === ($transform = $this->transformFor($field))) {
            return $value;
        }

        return $transform(TransformType::ENCODE, $value);
    }

    protected function decodeValue(string $field, mixed $value): mixed
    {
        if (null === ($transform = $this->transformFor($field))) {
            return $value;
        }

        return $transform(TransformType::DECODE, $value);
    }

    protected function transformFor(string $field): ?callable
    {
        $class = static::class;
        if (!isset(self::$transformerCache[$class])) {
            self::$transformerCache[$class] = static::resolveTransforms();
        }

        return self::$transformerCache[$class][$field] ?? null;
    }

    /**
     * @return array<string,callable>
     */
    private function differsForClass(): array
    {
        $class = static::class;
        if (!isset(self::$differCache[$class])) {
            self::$differCache[$class] = static::resolveDiffers();
        }

        return self::$differCache[$class];
    }

    private function invokeDiffer(callable $differ, mixed $previous, mixed $current, string $field): bool
    {
        $args = [$previous, $current, $this, $field];
        $reflection = $this->reflectCallable($differ);
        if (null === $reflection) {
            return true === (bool) $differ(...$args);
        }

        $required = $reflection->getNumberOfRequiredParameters();
        if (!$reflection->isVariadic() && $required > 4) {
            throw new RuntimeException('Differ callable requires too many arguments.');
        }

        if ($reflection->isVariadic()) {
            return true === (bool) $differ(...$args);
        }

        $count = min($reflection->getNumberOfParameters(), 4);

        return true === (bool) $differ(...array_slice($args, 0, $count));
    }

    /**
     * @return array<string,callable>
     */
    protected static function resolveDiffers(): array
    {
        $reflection = new ReflectionClass(static::class);
        $differs = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            foreach ($property->getAttributes(Differ::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $callback = self::resolveDifferCallable($attribute->newInstance()->callback);
                if (null === $callback) {
                    throw new RuntimeException(sprintf(
                        'Unable to resolve Differ callback for %s::$%s.',
                        static::class,
                        $property->getName(),
                    ));
                }

                $differs[$property->getName()] = $callback;
            }
        }

        return $differs;
    }

    private static function resolveDifferCallable(mixed $callback): ?callable
    {
        if ($callback instanceof \Closure) {
            return $callback;
        }

        if (is_string($callback)) {
            $callback = trim($callback);
            if ('' === $callback) {
                return null;
            }

            if (str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
                if ('' === trim($class) || '' === trim($method)) {
                    return null;
                }

                if (!class_exists($class)) {
                    return null;
                }

                if (!is_callable([$class, $method])) {
                    return null;
                }

                return [$class, $method];
            }

            if (is_callable($callback)) {
                return $callback;
            }

            if (function_exists($callback) && is_callable($callback)) {
                return $callback;
            }

            return null;
        }

        if (is_array($callback)) {
            $target = $callback[0] ?? null;
            $method = $callback[1] ?? null;

            if (is_object($target) && is_string($method) && is_callable([$target, $method])) {
                return [$target, $method];
            }

            if (!is_string($target) || !is_string($method)) {
                return null;
            }

            $target = trim($target);
            $method = trim($method);
            if ('' === $target || '' === $method || !class_exists($target)) {
                return null;
            }

            if (!is_callable([$target, $method])) {
                return null;
            }

            return [$target, $method];
        }

        return null;
    }

    private function reflectCallable(callable $callable): ?ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            $target = $callable[0] ?? null;
            $method = $callable[1] ?? null;
            if ((is_object($target) || is_string($target)) && is_string($method)) {
                return new ReflectionMethod($target, $method);
            }

            return null;
        }

        if (is_string($callable)) {
            if (str_contains($callable, '::')) {
                [$class, $method] = explode('::', $callable, 2);
                if ('' !== trim($class) && '' !== trim($method)) {
                    return new ReflectionMethod($class, $method);
                }
            }

            return new ReflectionFunction($callable);
        }

        if ($callable instanceof \Closure) {
            return new ReflectionFunction($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return null;
    }

    /**
     * @return array<string,callable>
     */
    protected static function resolveTransforms(): array
    {
        $reflection = new ReflectionClass(static::class);
        $transforms = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            foreach ($property->getAttributes(Transform::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $transforms[$property->getName()] = $attribute->newInstance()->makeCallable($property);
            }
        }

        return $transforms;
    }
}
