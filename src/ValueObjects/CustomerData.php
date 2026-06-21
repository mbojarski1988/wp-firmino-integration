<?php

declare(strict_types=1);

namespace FirminoIntegration\ValueObjects;

final class CustomerData
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $shortName,
        public readonly string $locality,
        public readonly string $countryCode,
        public readonly string $street,
        public readonly string $houseNo,
        public readonly string $flatNo,
        public readonly string $postCode,
        public readonly string $email,
        public readonly string $phone,
        public readonly ?string $tin = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'fullName'    => $this->fullName,
            'shortName'   => $this->shortName,
            'locality'    => $this->locality,
            'countryCode' => $this->countryCode,
            'street'      => $this->street,
            'houseNo'     => $this->houseNo,
            'flatNo'      => $this->flatNo,
            'postCode'    => $this->postCode,
            'email'       => $this->email,
            'phone'       => $this->phone,
        ];

        if ( $this->tin !== null && $this->tin !== '' ) {
            $data['tin'] = $this->tin;
        }

        return $data;
    }
}
