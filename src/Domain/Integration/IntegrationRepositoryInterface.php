<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/** Configurations du tenant courant. */
interface IntegrationRepositoryInterface
{
    /** @return list<Integration> */
    public function all(): array;

    /** @param bool $lock verrou d'écriture (dans une transaction) : un seul contrôle à la fois renouvelle le jeton */
    public function find(Provider $provider, bool $lock = false): ?Integration;

    /** Crée ou remplace la configuration du service. */
    public function save(Integration $integration): void;

    public function delete(Provider $provider): void;
}
