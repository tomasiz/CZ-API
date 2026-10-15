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

    /** Contenu d'un fichier stocké ; null s'il n'existe pas (ou si le chemin n'est pas celui d'un fichier de facture du tenant courant). */
    public function contents(string $path): ?string;

    /** Supprime un fichier stocké (annulation d'un import qui échoue en cours de route). */
    public function discard(string $path): void;
}
