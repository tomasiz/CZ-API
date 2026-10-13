<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Domain\Statement\StatementRow;
use Casamiza\Domain\Statement\StatementService;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\StatementResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Statut de facturation des propriétaires, mois par mois. */
final readonly class StatementController
{
    public function __construct(
        private StatementService $statements,
        private JsonResponder $json,
    ) {}

    /** `?ownerId=` et/ou `?month=AAAA-MM` (facultatifs) : le statut de chaque propriétaire et mois concernés. */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('ownerId', 'month');
        $ownerId = $input->optionalString('ownerId', 64);
        $month = $input->optionalString('month', 7);
        if ($month !== null && !BillingMonth::isValid($month)) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();

        return $this->json->data($response, array_map(
            static fn(StatementRow $row): array => StatementResource::toArray($row),
            $this->statements->grid($ownerId, $month),
        ));
    }

    /**
     * Déclare (`value: true`) ou annule (`value: false`) « pas de facture ce mois-ci ».
     *
     * @param array<string, string> $args
     */
    public function nothingToInvoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('value');
        $value = $input->boolean('value');
        if (!BillingMonth::isValid($args['month'])) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();

        return $this->json->data($response, StatementResource::toArray($this->statements->setNothingToInvoice($args['id'], $args['month'], $value)));
    }
}
