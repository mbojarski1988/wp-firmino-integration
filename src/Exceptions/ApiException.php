<?php

declare(strict_types=1);

namespace FirminoIntegration\Exceptions;

final class ApiException extends FirminoException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly mixed $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): mixed
    {
        return $this->responseBody;
    }
}
