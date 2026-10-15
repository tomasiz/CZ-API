<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Invoice\InvoiceRow;
use Casamiza\Domain\Invoice\InvoiceService;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Statement\BillingMonth;
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

    /** `?ownerId=` et/ou `?month=AAAA-MM` (facultatifs) : les factures du propriétaire et/ou du mois, annulées comprises. */
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
        $now = $this->clock->now();

        return $this->json->data($response, array_map(
            static fn(InvoiceRow $row): array => InvoiceResource::row($row, $now),
            $this->invoices->list($ownerId, $month),
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

    /** Mêmes champs que `create`. Renvoie le PDF « specimen » de la facture qui serait émise ; rien n'est enregistré. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('ownerId', 'month', 'note');
        $ownerId = $input->string('ownerId', 64);
        $month = $input->string('month', 7);
        $note = $input->optionalString('note', 500);
        $input->assertValid();

        return $this->file($response, $this->invoices->preview($ownerId, $month, $note));
    }

    /**
     * `month` (AAAA-MM, terminé et pas encore facturé). Renvoie le récapitulatif PDF du mois, calculé à l'instant et non conservé.
     *
     * @param array<string, string> $args
     */
    public function draftRecap(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('month');
        $month = $input->string('month', 7);
        $input->assertValid();

        return $this->file($response, $this->invoices->draftRecap($args['id'], $month));
    }

    /**
     * `status` (`paid` | `unpaid`), `method` (`wire`, `check`, `cash`, `credit_card`, `other` : obligatoire si payée) et `date` (`AAAA-MM-JJ`, aujourd'hui par défaut).
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
     * Le PDF de la facture (`/pdf`) ou de son récapitulatif lié (`/recap-pdf`), servi par l'API (jamais d'URL publique).
     * Le nom proposé au téléchargement est composé par le front.
     *
     * @param array<string, string> $args
     */
    public function pdf(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->file($response, $this->invoices->pdf($args['id'], false));
    }

    /** @param array<string, string> $args */
    public function recapPdf(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->file($response, $this->invoices->pdf($args['id'], true));
    }

    private function file(ResponseInterface $response, string $contents): ResponseInterface
    {
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="document.pdf"')
            ->withHeader('Cache-Control', 'private, no-store');
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
