<?php

declare(strict_types=1);

namespace arabcoders\database\Validator\Rules;

use arabcoders\database\Validator\ValidationException;
use arabcoders\database\Validator\ValidationType;

final readonly class ValidUrl
{
    /**
     * Constructor for ValidUrl validation rule.
     *
     * @param string $message Custom error message for invalid URLs.
     * @param bool $nullable Whether null values are considered valid.
     * @param bool $allowEmpty Whether empty string values are considered valid.
     *
     */
    public function __construct(
        private string $message = 'Value must be a valid URL.',
        private bool $nullable = false,
        private bool $allowEmpty = false,
    ) {}

    /**
     * Validate that a value is a valid URL, allowing null or empty values if configured.
     *
     * @param ValidationType $type Validation phase.
     * @param mixed $value Value being validated.
     * @param ?string $property Property name for error reporting.
     * @return void
     * @throws ValidationException If the value is not a valid URL or is not a string when non-empty.
     */
    public function __invoke(ValidationType $type, mixed $value, ?string $property = null): void
    {
        if ($this->nullable && null === $value) {
            return;
        }

        if ($this->allowEmpty && '' === $value) {
            return;
        }

        if (false === filter_var($value, FILTER_VALIDATE_URL)) {
            throw new ValidationException($this->message, $property, $value, $type);
        }
    }
}
