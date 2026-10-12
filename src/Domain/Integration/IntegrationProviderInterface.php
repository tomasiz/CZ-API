<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/**
 * Un service tiers : ce qu'il faut saisir et comment vérifier que l'accès fonctionne.
 * Ajouter un service = une implémentation + son enregistrement dans le conteneur (config/container.php).
 */
interface IntegrationProviderInterface
{
    public function provider(): Provider;

    /** @return list<CredentialField> tous obligatoires */
    public function fields(): array;

    /**
     * Vérifie l'accès avec les valeurs saisies (secrets et réglages fusionnés), en réutilisant `$cachedToken` s'il est valable.
     *
     * @param array<string, string> $values
     */
    public function check(array $values, ?AccessToken $cachedToken): CheckResult;
}
