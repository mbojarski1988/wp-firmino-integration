<?php

declare(strict_types=1);

namespace FirminoIntegration\Exceptions;

final class ValidationException extends FirminoException
{
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
