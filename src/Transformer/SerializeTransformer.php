<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

use Closure;

final class SerializeTransformer
{
    private static Closure $encode;
    private static Closure $decode;

    public function __construct(bool $allowClasses = true)
    {
        if (extension_loaded('igbinary')) {
            self::$encode = \igbinary_serialize(...);
            self::$decode = \igbinary_unserialize(...);
        } else {
            self::$encode = serialize(...);
            self::$decode = static fn(string $data) => unserialize($data, ['allowed_classes' => $allowClasses]);
        }
    }

    public static function create(bool $allowClasses = true): callable
    {
        $class = new self($allowClasses);
        return $class(...);
    }

    /**
     * Transform the value according to the requested transform direction.
     * @param TransformType $type Type.
     * @param mixed $data Data.
     * @return mixed
     */

    public function __invoke(TransformType $type, mixed $data): mixed
    {
        return match ($type) {
            TransformType::ENCODE => (self::$encode)($data),
            TransformType::DECODE => (self::$decode)($data),
        };
    }
}
