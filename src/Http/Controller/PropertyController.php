<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Property\PropertyService;
use Casamiza\Http\Resource\PropertyResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PropertyController
{
    public function __construct(
        private PropertyService $properties,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(
            static fn(array $row): array => PropertyResource::toArray($row['property'], $row['ownerName'], $row['ownerActive']),
            $this->properties->list(),
        ));
    }
}
