<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use DateTimeImmutable;

/**
 * Journal d'audit. L'écriture porte son tenant dans l'entrée (une tentative de connexion n'a pas encore de contexte de
 * tenant) ; la lecture est bornée au tenant courant. Aucune modification : seul `purgeBefore` supprime.
 */
interface AuditLogRepositoryInterface
{
    public function add(AuditEntry $entry): void;

    /** @return array{items: list<AuditEntry>, total: int} du plus récent au plus ancien, tenant courant */
    public function search(AuditFilter $filter): array;

    /** Supprime les lignes (tous les tenants) antérieures à la date. @return int lignes supprimées */
    public function purgeBefore(DateTimeImmutable $before): int;
}
