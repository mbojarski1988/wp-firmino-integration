<?php

declare(strict_types=1);

namespace FirminoIntegration\ValueObjects;

final class DocumentItem
{
    public function __construct(
        public readonly string $name,
        public readonly string $unit,
        public readonly string $vatRate,
        public readonly string $price,
        public readonly string $quantity,
        public readonly string $articleType,
    ) {}

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'unit'        => $this->unit,
            'vatRate'     => $this->vatRate,
            'price'       => $this->price,
            'quantity'    => $this->quantity,
            'articleType' => $this->articleType,
        ];
    }
}
