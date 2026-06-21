<?php

declare(strict_types=1);

namespace FirminoIntegration\ValueObjects;

final class ImportResult
{
    public function __construct(
        public readonly int $added,
        public readonly int $updated,
        public readonly int $skipped,
        public readonly int $errors,
        public readonly bool $hasMore,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            added:   $this->added   + $other->added,
            updated: $this->updated + $other->updated,
            skipped: $this->skipped + $other->skipped,
            errors:  $this->errors  + $other->errors,
            hasMore: $other->hasMore,
        );
    }

    public function summary(): string
    {
        return sprintf(
            __( 'Import zakończony. Dodane: %1$d, Zaktualizowane: %2$d, Pominięte: %3$d, Błędy: %4$d.', 'firmino-integration' ),
            $this->added,
            $this->updated,
            $this->skipped,
            $this->errors,
        );
    }
}
