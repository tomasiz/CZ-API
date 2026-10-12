<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/**
 * Champ à saisir pour configurer un service. Un champ `secret` est chiffré, jamais renvoyé et peut rester
 * vide en modification (valeur inchangée) ; les autres sont des réglages lisibles stockés en clair.
 */
final readonly class CredentialField
{
    public function __construct(
        public string $key,
        public bool $secret = false,
        public int $maxLength = 255,
    ) {}
}
