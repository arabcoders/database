<?php

declare(strict_types=1);

namespace arabcoders\database\Validator\Rules;

use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;

final readonly class Regex
{
    public function __construct(
        private string $pattern,
        private string $message = 'Value does not match required format.',
    ) {}

    /**
     * Validate a string value against the configured regular expression pattern.
     *
     * @param ValidationType $type Validation phase.
     * @param mixed $value Value being validated.
     * @param ?string $property Property name for error reporting.
     * @return void
     * @throws ValidationException If the value is not a string or does not match the pattern.
     */
    public function __invoke(ValidationType $type, mixed $value, ?string $property = null): void
    {
        if (!is_string($value)) {
            throw new ValidationException($this->message, $property, $value, $type);
        }

        if (preg_match($this->pattern, $value)) {
            return;
        }

        throw new ValidationException($this->message, $property, $value, $type);
    }
}
