<?php

declare(strict_types=1);

namespace arabcoders\database;

use PDOException;
use Throwable;

final class DatabaseException extends PDOException
{
    private string $queryString = '';

    /**
     * @var array<string,mixed>
     */
    private array $queryBind = [];

    public static function fromPdo(
        PDOException $exception,
        string $query = '',
        array $params = [],
    ): self {
        if ($exception instanceof self) {
            return $exception;
        }

        $wrapped = self::create(
            message: $exception->getMessage(),
            query: $query,
            params: $params,
            errorInfo: $exception->errorInfo,
            code: $exception->getCode(),
            previous: $exception,
        );

        $wrapped->file = $exception->getFile();
        $wrapped->line = $exception->getLine();

        return $wrapped;
    }

    public static function forOperationFailure(string $message, string $query = '', array $params = []): self
    {
        return self::create(message: $message, query: $query, params: $params);
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    /**
     * @return array<string,mixed>
     */
    public function getQueryBind(): array
    {
        return $this->queryBind;
    }

    private static function create(
        string $message,
        string $query = '',
        array $params = [],
        ?array $errorInfo = null,
        int|string $code = 0,
        ?Throwable $previous = null,
    ): self {
        $wrapped = new self($message, is_int($code) ? $code : 0, $previous);
        $wrapped->queryString = $query;
        $wrapped->queryBind = $params;
        $wrapped->errorInfo = $errorInfo;
        $wrapped->code = $code;

        return $wrapped;
    }
}
