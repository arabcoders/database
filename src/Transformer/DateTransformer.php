<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

final readonly class DateTransformer
{
    public function __construct(
        private bool $nullable = false,
        private string $format = DateTimeInterface::ATOM,
    ) {}

    public static function create(bool $nullable = false, string $format = DateTimeInterface::ATOM): callable
    {
        $class = new self($nullable, $format);
        return $class(...);
    }

    /**
     * Transform the value according to the requested transform direction.
     * @param TransformType $type Type.
     * @param mixed $data Data.
     * @return string|DateTimeInterface|null
     * @throws RuntimeException
     */
    public function __invoke(TransformType $type, mixed $data): string|null|DateTimeInterface
    {
        if (null === $data) {
            if ($this->nullable) {
                return null;
            }

            throw new RuntimeException('Date cannot be null');
        }

        $isDate = true === $data instanceof DateTimeInterface;

        if (false === $isDate && !is_string($data)) {
            if (true === ctype_digit((string) $data)) {
                $isDate = true;
                $data = self::normalizeDate($data);
            } else {
                throw new RuntimeException('Date must be a string or an instance of DateTimeInterface');
            }
        }

        return match ($type) {
            TransformType::ENCODE => $isDate ? $data->format($this->format) : (string) $data,
            TransformType::DECODE => self::normalizeDate($data),
        };
    }

    private static function normalizeDate(mixed $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value) || is_string($value) && ctype_digit($value)) {
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
}
