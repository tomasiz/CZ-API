<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/** Identifiant de la requête en cours, repris dans les logs et les réponses d'erreur. */
final class RequestContext
{
    private ?string $requestId = null;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
