<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Un PDF rangé dans le stockage : chemin relatif à la racine de stockage et empreinte SHA-256 de son contenu. */
final readonly class StoredInvoiceFile
{
    public function __construct(
        public string $path,
        public string $sha256,
    ) {}
}
