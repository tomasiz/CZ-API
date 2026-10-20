<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

final readonly class StoredBeyondFile
{
    public function __construct(
        public string $path,
        public string $sha256,
    ) {}
}
