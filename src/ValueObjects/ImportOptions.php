<?php

declare(strict_types=1);

namespace FirminoIntegration\ValueObjects;

final class ImportOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly int $offset,
        public readonly string $codeSource,
        public readonly bool $includeVariations,
        public readonly bool $updateExisting,
        public readonly bool $forceImport,
    ) {}
}
