<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceService;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\InvoiceResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Factures (administrateurs seulement, voir les routes). */
final readonly class InvoiceController
{
    public function __construct(
        private InvoiceService $invoices,
        private ClockInterface $clock,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = $this->clock->now();

        return $this->json->data($response, array_map(
            static fn(Invoice $invoice): array => InvoiceResource::toArray($invoice, $now),
            $this->invoices->list(),
        ));
    }

    /** `ownerId`, `month` (AAAA-MM, terminé) et `note` (facultative, 500 caractères). Émet la facture. */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('ownerId', 'month', 'note');
        $ownerId = $input->string('ownerId', 64);
        $month = $input->string('month', 7);
        $note = $input->optionalString('note', 500);
        $input->assertValid();

        $invoice = $this->invoices->create($ownerId, $month, $note);

        return $this->json->data($response, InvoiceResource::toArray($invoice, $this->clock->now()), 201);
    }

    /**
     * `status` (`paid` | `unpaid`), `method` (`wire`, `cash`, `credit_card`, `other` : obligatoire si payée) et `date` (`AAAA-MM-JJ`, aujourd'hui par défaut).
     *
     * @param array<string, string> $args
     */
    public function payment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('status', 'method', 'date');
        $status = $input->enum('status', PaymentStatus::class);
        $method = $input->optionalEnum('method', PaymentMethod::class);
        $date = $input->optionalDate('date');
        $input->assertValid();
        if ($status === null) {
            throw new ValidationException(['status' => ['required']]);
        }

        $invoice = $this->invoices->setPayment($args['id'], $status, $method, $date);

        return $this->json->data($response, InvoiceResource::toArray($invoice, $this->clock->now()));
    }

    /**
     * L'instantané JSON de la facture (structure de Facturiza), tel qu'il a été figé.
     *
     * @param array<string, string> $args
     */
    public function snapshot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $snapshot = $this->invoices->find($args['id'])->snapshot ?? throw new NotFoundException('snapshot_not_found');

        return $this->json->data($response, $snapshot);
    }
}
