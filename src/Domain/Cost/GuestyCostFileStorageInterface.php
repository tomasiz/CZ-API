<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Stockage des fichiers de factures Guesty du tenant courant (hors webroot, noms aléatoires). */
interface GuestyCostFileStorageInterface
{
    /** Range ce fichier sous un nom aléatoire et renvoie son chemin relatif et son empreinte SHA-256. */
    public function store(string $bytes, string $year): StoredBeyondFile;

    /** Contenu d'un fichier rangé ; null s'il n'existe pas (ou si le chemin n'est pas celui d'une facture du tenant courant). */
    public function contents(string $path): ?string;

    public function discard(string $path): void;
}
