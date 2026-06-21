<?php

declare(strict_types=1);

namespace FirminoIntegration\Contracts;

use FirminoIntegration\Exceptions\ApiException;

interface HttpClientInterface
{
    /**
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function request(string $endpoint, array $body = [], string $method = 'POST'): array;

    /**
     * Streams the response to a temp file and returns its path.
     *
     * @throws ApiException
     */
    public function streamToFile(string $endpoint): string;
}
