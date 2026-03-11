<?php
declare(strict_types=1);

namespace arabcoders\database\Transformer;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class TimestampTransformer
{
    public function __construct(
        private bool $nullable = false,
    ) {}

    public static function create(bool $nullable = false): callable
    {
        $class = new self(nullable: $nullable);
        return $class(...);
    }

    public function __invoke(TransformType $type, mixed $data): int|string|null|DateTimeInterface
    {
        if (null === $data) {
            if ($this->nullable) {
                return null;
            }

            throw new RuntimeException('Date cannot be null');
        }

        $isDate = true === $data instanceof DateTimeInterface;

        if (false === $isDate && !ctype_digit((string) $data)) {
            if (is_string($data)) {
                $isDate = true;
                $data = self::normalizeDate($data);
            } else {
                throw new RuntimeException(sprintf(
                    "Date must be a integer or DateTime. '%s('%s')' given.",
                    get_debug_type($data),
                    self::stringifyValue($data),
                ));
            }
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->getTimestamp() : $data,
            TransformType::DECODE => $isDate ? $data : self::normalizeDate($data),
        };
    }

    private static function normalizeDate(mixed $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $date = new DateTimeImmutable('@' . (string) $value);

            return $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        if (is_string($value) && '' !== trim($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to parse date value: ' . $value, 0, $exception);
            }
        }

        throw new RuntimeException('Unable to normalize date value.');
    }

    private static function stringifyValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? get_debug_type($value) : $encoded;
    }
}
