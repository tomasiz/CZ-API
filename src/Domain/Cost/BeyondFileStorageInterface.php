<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Stockage des CSV de relevés Beyond du tenant courant (hors webroot, noms aléatoires). */
interface BeyondFileStorageInterface
{
    /** Range ce CSV sous un nom aléatoire et renvoie son chemin relatif et son empreinte SHA-256. */
    public function store(string $csv, BeyondPeriod $period): StoredBeyondFile;

    /** Contenu d'un CSV rangé ; null s'il n'existe pas (ou si le chemin n'est pas celui d'un relevé du tenant courant). */
    public function contents(string $path): ?string;

    /** Supprime un CSV rangé (traitement annulé, ou fichier remplacé). */
    public function discard(string $path): void;
}
