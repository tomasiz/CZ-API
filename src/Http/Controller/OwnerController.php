<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Owner\OwnerService;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\OwnerResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class OwnerController
{
    public function __construct(
        private OwnerService $owners,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(
            static fn(array $row): array => OwnerResource::toArray($row['owner'], $row['properties']),
            $this->owners->list(),
        ));
    }

    /**
     * Saisies locales : `companyName`, `siret` (vide = effacer), `stripeKey` (écriture seule, jamais renvoyée).
     *
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('companyName', 'siret', 'stripeKey');

        $companyName = $input->has('companyName') ? $input->text('companyName', 190) : null;
        $siret = null;
        if ($input->has('siret')) {
            $siret = (string) preg_replace('/\s+/', '', $input->text('siret', 20));
            // SIRET (14 chiffres) ou SIREN (9 chiffres) : certains propriétaires n'ont donné que leur SIREN.
            if ($siret !== '' && preg_match('/^(\d{9}|\d{14})$/', $siret) !== 1) {
                $input->fail('siret');
            }
        }
        $stripeKey = $input->optionalString('stripeKey', 255);
        if ($stripeKey !== null && preg_match('/^(sk|rk)_(live|test)_\w{10,}$/', $stripeKey) !== 1) {
            $input->fail('stripeKey');
        }
        $input->assertValid();
        if ($companyName === null && $siret === null && $stripeKey === null) {
            throw new ValidationException(['companyName' => ['required']]);
        }

        $row = $this->owners->update($args['id'], $companyName, $siret, $stripeKey);

        return $this->json->data($response, OwnerResource::toArray($row['owner'], $row['properties']));
    }

    /** @param array<string, string> $args */
    public function removeStripeKey(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->owners->removeStripeKey($args['id']);

        return $this->json->data($response, OwnerResource::toArray($row['owner'], $row['properties']));
    }
}
