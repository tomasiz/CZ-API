<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

/** Bilan de l'import des factures de Facturiza. Avec des `errors`, rien n'est écrit (même avec `--apply`). */
final readonly class InvoiceImportReport
{
    /**
     * @param list<string> $errors vérifications d'intégrité en échec : l'import ne s'exécute pas
     * @param list<string> $warnings valeurs particulières reprises ou ignorées
     */
    public function __construct(
        public bool $applied,
        public int $invoices,
        public int $invoicesAlreadyImported,
        public int $lines,
        public int $reservations,
        public int $files,
        public int $extras,
        public int $extrasAlreadyImported,
        public int $nothingToInvoice,
        public int $nextNumber,
        public array $errors,
        public array $warnings,
    ) {}
}
