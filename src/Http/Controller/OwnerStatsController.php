<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\OwnerStats\OwnerStatsService;
use Casamiza\Http\Resource\OwnerStatsResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Statistiques d'un propriétaire (administrateurs seulement) : les filtres et les comparaisons se font dans le front. */
final readonly class OwnerStatsController
{
    public function __construct(
        private OwnerStatsService $stats,
        private JsonResponder $json,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->data($response, OwnerStatsResource::toArray($this->stats->forOwner($args['id'])));
    }
}
