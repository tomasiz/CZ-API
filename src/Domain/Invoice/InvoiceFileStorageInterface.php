<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Stockage des PDF de factures et de récapitulatifs du tenant courant (hors webroot, noms aléatoires). */
interface InvoiceFileStorageInterface
{
    /**
     * Copie un fichier PDF dans le stockage du tenant, sous un nom aléatoire, dans le dossier de l'année.
     *
     * @param string $year `AAAA`
     */
    public function store(string $sourcePath, string $year): StoredInvoiceFile;

    /** Supprime un fichier stocké (annulation d'un import qui échoue en cours de route). */
    public function discard(string $path): void;
}
