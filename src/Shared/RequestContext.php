<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/** Ce qui identifie la requête en cours : repris dans les logs, les réponses d'erreur et le journal d'audit. */
final class RequestContext
{
    private ?string $requestId = null;
    private ?string $ip = null;
    private ?string $actorId = null;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function setIp(?string $ip): void
    {
        $this->ip = $ip;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    /** Utilisateur du jeton d'accès (renseigné par AuthMiddleware). */
    public function setActorId(string $actorId): void
    {
        $this->actorId = $actorId;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /** Remise à zéro en début de requête : un processus durable ne garde rien d'une requête à l'autre. */
    public function reset(): void
    {
        $this->requestId = null;
        $this->ip = null;
        $this->actorId = null;
    }
}
