<?php

declare(strict_types=1);

namespace arabcoders\database\Validator\Rules;

use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;

final readonly class NoSpaces
{
    public function __construct(
        private string $message = 'Value must not contain spaces.',
    ) {}

    /**
     * Validate that a string value does not contain whitespace characters.
     *
     * @param ValidationType $type Validation phase.
     * @param mixed $value Value being validated.
     * @param ?string $property Property name for error reporting.
     * @return void
     * @throws ValidationException If the value is not a string or contains spaces.
     */
    public function __invoke(ValidationType $type, mixed $value, ?string $property = null): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new ValidationException($this->message, $property, $value, $type);
        }

        if (preg_match('/\s/', $value)) {
            throw new ValidationException($this->message, $property, $value, $type);
        }
    }
}
