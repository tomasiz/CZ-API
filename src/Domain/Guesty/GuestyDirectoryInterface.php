<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

/**
 * Annuaire Guesty du tenant courant. Chaque méthode parcourt **toutes** les pages : une liste partielle ne doit
 * jamais être renvoyée (la synchronisation archive ce qui est absent).
 */
interface GuestyDirectoryInterface
{
    /**
     * @return list<GuestyOwner>
     * @throws \Casamiza\Shared\Exception\ApiException Guesty injoignable, identifiants refusés, limite atteinte…
     */
    public function owners(): array;

    /**
     * @return list<GuestyListing>
     * @throws \Casamiza\Shared\Exception\ApiException
     */
    public function listings(): array;
}
