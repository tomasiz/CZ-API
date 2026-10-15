<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Dashboard\DashboardService;
use Casamiza\Http\Resource\DashboardResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Chiffres du tableau de bord (administrateurs seulement) : filtres et agrégations se font dans le front. */
final readonly class DashboardController
{
    public function __construct(
        private DashboardService $dashboard,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, DashboardResource::toArray($this->dashboard->overview()));
    }
}
