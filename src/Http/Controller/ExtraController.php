<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Extra\ExtraService;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\ExtraResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Prestations supplémentaires du mois d'un propriétaire (ménage, linge, conciergerie). */
final readonly class ExtraController
{
    public function __construct(
        private ExtraService $extras,
        private JsonResponder $json,
    ) {}

    /**
     * `?month=AAAA-MM` (obligatoire). Renvoie les prestations et les taux de TVA proposés par type.
     *
     * @param array<string, string> $args
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('month');
        $month = $this->month($input);
        $input->assertValid();

        return $this->json->data($response, ExtraResource::sheet($this->extras->list($args['ownerId'], $month), $this->extras->vatRates()));
    }

    /**
     * `month`, `type`, `description`, `priceTtc` (négatif accepté : un avoir) et `vatRate` (facultatif : la TVA proposée pour le type).
     *
     * @param array<string, string> $args
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('month', 'type', 'description', 'priceTtc', 'vatRate');
        $month = $this->month($input);
        $type = $input->enum('type', ExtraType::class);
        $description = $input->string('description', 500);
        $price = $input->money('priceTtc', true);
        $vatRate = $input->optionalNumber('vatRate', 0, 100);
        $input->assertValid();
        if ($type === null || $price === null) {
            throw new ValidationException(['type' => ['required']]);
        }

        $extra = $this->extras->add($args['ownerId'], $month, $type, $description, $price, $vatRate);

        return $this->json->data($response, ExtraResource::toArray($extra), 201);
    }

    /**
     * Modification partielle : `type`, `description`, `priceTtc`, `vatRate`. Le mois ne change pas.
     *
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('type', 'description', 'priceTtc', 'vatRate');
        $type = $input->has('type') ? $input->enum('type', ExtraType::class) : null;
        $description = $input->has('description') ? $input->string('description', 500) : null;
        $price = $input->has('priceTtc') ? $input->money('priceTtc', true) : null;
        $vatRate = $input->has('vatRate') ? $input->optionalNumber('vatRate', 0, 100) : null;
        $input->assertValid();
        if ($type === null && $description === null && $price === null && $vatRate === null) {
            throw new ValidationException(['description' => ['required']]);
        }

        return $this->json->data($response, ExtraResource::toArray($this->extras->update($args['ownerId'], $args['id'], $type, $description, $price, $vatRate)));
    }

    /** @param array<string, string> $args */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->extras->remove($args['ownerId'], $args['id']);

        return $this->json->noContent($response);
    }

    private function month(Input $input): string
    {
        $month = $input->string('month', 7);
        if ($month !== '' && !BillingMonth::isValid($month)) {
            $input->fail('month', 'invalid_value');
        }

        return $month;
    }
}
