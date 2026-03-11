<?php

declare(strict_types=1);

namespace arabcoders\database\Validator;

use RuntimeException;
use Throwable;

final class ValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $property = null,
        public readonly mixed $value = null,
        public readonly ?ValidationType $type = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
