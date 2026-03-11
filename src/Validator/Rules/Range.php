<?php

declare(strict_types=1);

namespace arabcoders\database\Validator\Rules;

use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;

final readonly class Range
{
    public function __construct(
        private int $min = 0,
        private int $max = PHP_INT_MAX,
        private string $message = 'The value must be between {min} and {max}',
    ) {}

    /**
     * Validate that numeric strings, string lengths, or array sizes stay within range bounds.
     *
     * @param ValidationType $type Validation phase.
     * @param mixed $value Value being validated.
     * @param ?string $property Property name for error reporting.
     * @return void
     * @throws ValidationException If the value is outside the configured range.
     */
    public function __invoke(ValidationType $type, mixed $value, ?string $property = null): void
    {
        if (is_string($value)) {
            if (true === ctype_digit($value)) {
                if ($value >= $this->min && $value <= $this->max) {
                    return;
                }

                throw new ValidationException($this->formatMessage(), $property, $value, $type);
            }

            $length = mb_strlen(trim($value));
            if ($length >= $this->min && $length <= $this->max) {
                return;
            }

            throw new ValidationException($this->formatMessage(), $property, $value, $type);
        }

        if (is_array($value)) {
            $count = count($value);
            if ($count >= $this->min && $count <= $this->max) {
                return;
            }

            throw new ValidationException($this->formatMessage(), $property, $value, $type);
        }

        throw new ValidationException($this->formatMessage(), $property, $value, $type);
    }

    private function formatMessage(): string
    {
        return strtr($this->message, [
            '{min}' => (string) $this->min,
            '{max}' => (string) $this->max,
        ]);
    }
}
