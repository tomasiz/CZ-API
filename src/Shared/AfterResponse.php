<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/**
 * Exécute un traitement **après** que la réponse a été envoyée au client. Sert aux actions dont la durée trahirait une
 * information (ex. : « mot de passe oublié » est plus long pour une adresse connue, puisqu'un e-mail part).
 */
interface AfterResponse
{
    /** @param callable(): void $work l'exception qu'il lève est journalisée, jamais renvoyée au client */
    public function run(callable $work): void;
}
