<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/** Mentions mensuelles du tenant courant (une par propriétaire et par mois). */
interface StatementRepositoryInterface
{
    /**
     * Les mentions existantes, restreintes à un propriétaire et/ou à un mois si fournis.
     *
     * @return list<Statement>
     */
    public function where(?string $ownerId, ?string $month): array;

    public function find(string $ownerId, string $month): ?Statement;

    /** Insère la mention ou réécrit celle du même (propriétaire, mois). */
    public function save(Statement $statement): void;
}
