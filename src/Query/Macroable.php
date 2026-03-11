<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use Closure;
use RuntimeException;

trait Macroable
{
    /**
     * @var array<string,callable>
     */
    protected static array $macros = [];

    /**
     * Register a runtime macro callable by name.
     *
     * @param string $name Macro name.
     * @param callable $macro Macro implementation.
     * @return void
     * @throws RuntimeException If the macro name is empty.
     */
    public static function macro(string $name, callable $macro): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('Macro name is required.');
        }

        static::$macros[$name] = $macro;
    }

    public static function hasMacro(string $name): bool
    {
        return array_key_exists($name, static::$macros);
    }

    public static function flushMacros(): void
    {
        static::$macros = [];
    }

    /**
     * Invoke a previously registered macro.
     *
     * @param string $name Macro name.
     * @param array<int,mixed> $arguments Call arguments.
     * @return mixed
     * @throws RuntimeException If the macro is not registered.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!static::hasMacro($name)) {
            throw new RuntimeException('Undefined macro: ' . $name);
        }

        $macro = static::$macros[$name];
        if ($macro instanceof Closure) {
            $bound = $macro->bindTo($this, static::class);
            return $bound(...$arguments);
        }

        return $macro($this, ...$arguments);
    }
}
