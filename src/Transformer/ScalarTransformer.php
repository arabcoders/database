<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

final class ScalarTransformer
{
    public function __construct(
        private ScalarType $scalarType,
        private bool $nullable = false,
    ) {}

    public static function create(ScalarType $scalarType, bool $nullable = false): callable
    {
        $class = new self($scalarType, $nullable);
        return $class(...);
    }

    /**
     * Transform the value according to the requested transform direction.
     * @param TransformType $type Type.
     * @param mixed $value Value.
     * @return mixed
     */

    public function __invoke(TransformType $type, mixed $value): mixed
    {
        return match ($type) {
            TransformType::ENCODE => $this->encode($value),
            TransformType::DECODE => $this->decode($value),
        };
    }

    private function encode(mixed $value): mixed
    {
        return $this->decode($value);
    }

    private function decode(mixed $data): mixed
    {
        if (null === $data) {
            if (true === $this->nullable) {
                return null;
            }
            throw new \InvalidArgumentException('Data cannot be null');
        }

        return match ($this->scalarType) {
            ScalarType::STRING => (string) $data,
            ScalarType::INT => (int) $data,
            ScalarType::FLOAT => (float) $data,
            ScalarType::BOOL => (bool) $data,
        };
    }
}
