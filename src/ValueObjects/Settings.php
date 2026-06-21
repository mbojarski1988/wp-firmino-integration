<?php

declare(strict_types=1);

namespace FirminoIntegration\ValueObjects;

final class Settings
{
    private const OPTION_KEY = 'firmino_settings';

    private const DEFAULTS = [
        'base_url'              => 'https://app.firmino.pl/app/services/rest/api/',
        'login'                 => '',
        'password'              => '',
        'document_type'         => 'fas',
        'receipt_document_type' => '',
        'default_vat_rate'      => '',
        'default_country'       => 'PL',
        'default_locality'      => 'Warszawa',
    ];

    private function __construct(
        public readonly string $baseUrl,
        public readonly string $login,
        public readonly string $password,
        public readonly string $documentType,
        public readonly string $receiptDocumentType,
        public readonly string $defaultVatRate,
        public readonly string $defaultCountry,
        public readonly string $defaultLocality,
    ) {}

    public static function fromOption(): self
    {
        /** @var array<string,string> $raw */
        $raw = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::DEFAULTS );

        return new self(
            baseUrl:             rtrim( (string) $raw['base_url'], '/' ) . '/',
            login:               (string) $raw['login'],
            password:            (string) $raw['password'],
            documentType:        (string) $raw['document_type'],
            receiptDocumentType: (string) $raw['receipt_document_type'],
            defaultVatRate:      (string) $raw['default_vat_rate'],
            defaultCountry:      (string) $raw['default_country'],
            defaultLocality:     (string) $raw['default_locality'],
        );
    }

    public static function optionKey(): string
    {
        return self::OPTION_KEY;
    }

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public function hasCredentials(): bool
    {
        return $this->login !== '' || $this->password !== '';
    }

    public function authHeader(): string
    {
        return 'Basic ' . base64_encode( $this->login . ':' . $this->password );
    }
}
