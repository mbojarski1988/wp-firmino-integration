<?php

declare(strict_types=1);

namespace FirminoIntegration\Http;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\ValueObjects\Settings;

final class WordPressHttpClient implements HttpClientInterface
{
    public function __construct(private readonly Settings $settings) {}

    public function request(string $endpoint, array $body = [], string $method = 'POST'): array
    {
        $url  = $this->settings->baseUrl . ltrim($endpoint, '/');
        $args = $this->buildArgs($method, $body);

        $response = wp_remote_request($url, $args);

        if ( is_wp_error($response) ) {
            throw new ApiException(
                $response->get_error_message(),
                0,
                null,
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody    = wp_remote_retrieve_body($response);
        $data       = json_decode($rawBody, true);

        if ( $statusCode !== 200 ) {
            $this->maybeLog($endpoint, $body, ['status' => $statusCode, 'body' => $rawBody]);
            throw new ApiException(
                sprintf('Firmino API HTTP %d', $statusCode),
                $statusCode,
                $data ?? $rawBody,
            );
        }

        if ( $data === null && $rawBody !== '' ) {
            throw new ApiException(
                'API Firmino zwróciło nieprawidłowy JSON.',
                $statusCode,
                $rawBody,
            );
        }

        if ( is_array($data) && isset($data['success']) && $data['success'] === false ) {
            $message = $this->extractErrorMessage($data);
            $this->maybeLog($endpoint, $body, $data);
            throw new ApiException($message, $statusCode, $data);
        }

        return $data ?? [];
    }

    public function streamToFile(string $endpoint): string
    {
        $url      = $this->settings->baseUrl . ltrim($endpoint, '/');
        $filename = wp_tempnam('firmino-document-.pdf');

        if ( ! $filename ) {
            throw new ApiException('Nie można utworzyć pliku tymczasowego.');
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/pdf'];
        if ( $this->settings->hasCredentials() ) {
            $headers['Authorization'] = $this->settings->authHeader();
        }

        $response = wp_remote_request($url, [
            'method'   => 'POST',
            'headers'  => $headers,
            'body'     => '{}',
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $filename,
        ]);

        if ( is_wp_error($response) ) {
            @unlink($filename);
            throw new ApiException($response->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ( $statusCode !== 200 ) {
            @unlink($filename);
            throw new ApiException(
                sprintf('Firmino API HTTP %d', $statusCode),
                $statusCode,
            );
        }

        if ( ! $this->isValidPdf($filename, wp_remote_retrieve_headers($response)) ) {
            $body = file_exists($filename) ? (string) file_get_contents($filename) : '';
            @unlink($filename);
            throw new ApiException(
                'Firmino nie zwróciło prawidłowego pliku PDF.',
                $statusCode,
                $body,
            );
        }

        return $filename;
    }

    private function buildArgs(string $method, array $body): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ( $this->settings->hasCredentials() ) {
            $headers['Authorization'] = $this->settings->authHeader();
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 20,
        ];

        if ( ! empty($body) ) {
            $args['body'] = wp_json_encode($body);
        }

        return $args;
    }

    private function isValidPdf(string $filename, mixed $headers): bool
    {
        $contentType = '';
        if ( is_array($headers) && isset($headers['content-type']) ) {
            $contentType = (string) $headers['content-type'];
        } elseif ( is_object($headers) && method_exists($headers, 'offsetGet') ) {
            $contentType = (string) ($headers['content-type'] ?? '');
        }

        if ( str_contains($contentType, 'application/pdf') ) {
            return true;
        }

        $handle = @fopen($filename, 'rb');
        if ( $handle === false ) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === '%PDF';
    }

    private function extractErrorMessage(array $data): string
    {
        if ( isset($data['response']) && is_string($data['response']) && trim($data['response']) !== '' ) {
            return $data['response'];
        }

        if ( ! empty($data['message']) ) {
            return (string) $data['message'];
        }

        if ( ! empty($data['error']) ) {
            return (string) $data['error'];
        }

        if ( ! empty($data['errors']) && is_array($data['errors']) ) {
            $messages = [];
            foreach ( $data['errors'] as $key => $value ) {
                if ( is_array($value) ) {
                    $messages = array_merge($messages, $value);
                } else {
                    $messages[] = sprintf('%s: %s', $key, $value);
                }
            }

            $messages = array_filter(array_map('trim', $messages));
            if ( ! empty($messages) ) {
                return implode('; ', $messages);
            }
        }

        return 'Błąd API Firmino';
    }

    private function maybeLog(string $endpoint, array $payload, mixed $response): void
    {
        if ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
            error_log('Firmino API error: ' . wp_json_encode([
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'response' => $response,
            ]));
        }
    }
}
