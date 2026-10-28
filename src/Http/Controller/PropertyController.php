<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Extra\ExtraService;
use Casamiza\Domain\Property\PropertyService;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\PropertyResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PropertyController
{
    public function __construct(
        private PropertyService $properties,
        private ExtraService $extras,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $vatRates = $this->extras->vatRates();

        return $this->json->data($response, array_map(
            static fn(array $row): array => PropertyResource::toArray($row['property'], $row['ownerName'], $row['ownerActive'], $vatRates),
            $this->properties->list(),
        ));
    }

    /**
     * Affectation du logement : `ownerId` obligatoire (UUID d'un propriétaire, ou null pour n'en désigner aucun).
     *
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('ownerId');
        if (!$input->has('ownerId')) {
            $input->fail('ownerId', 'required');
        }
        $ownerId = $input->optionalString('ownerId', 36);
        $input->assertValid();

        $result = $this->properties->assignOwner($args['id'], $ownerId);

        return $this->json->data($response, PropertyResource::toArray($result['row']['property'], $result['row']['ownerName'], $result['row']['ownerActive'], $this->extras->vatRates()) + [
            'movedReservations' => $result['movedReservations'],
        ]);
    }
}
