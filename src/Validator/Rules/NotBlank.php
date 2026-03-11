<?php

declare(strict_types=1);

namespace arabcoders\database\Validator\Rules;

use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;

final readonly class NotBlank
{
    public function __construct(
        private string $message = 'This field cannot be blank',
    ) {}

    /**
     * Validate that the value is not blank for strings, arrays, or scalar values.
     *
     * @param ValidationType $type Validation phase.
     * @param mixed $value Value being validated.
     * @param ?string $property Property name for error reporting.
     * @return void
     * @throws ValidationException If the value is blank.
     */
    public function __invoke(ValidationType $type, mixed $value, ?string $property = null): void
    {
        if (is_string($value)) {
            if (!preg_match('/^\s*$/u', $value) && '' !== $value) {
                return;
            }

            throw new ValidationException($this->message, $property, $value, $type);
        }

        if (is_array($value)) {
            if (count($value) > 0) {
                return;
            }

            throw new ValidationException($this->message, $property, $value, $type);
        }

        if (!empty($value)) {
            return;
        }

        throw new ValidationException($this->message, $property, $value, $type);
    }
}
