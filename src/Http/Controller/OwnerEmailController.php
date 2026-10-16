<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Mail\DocumentEmailService;
use Casamiza\Domain\Mail\EmailDocument;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** E-mail de la facture et/ou du récapitulatif d'un propriétaire pour un mois (administrateurs seulement, voir les routes). */
final readonly class OwnerEmailController
{
    public function __construct(
        private DocumentEmailService $emails,
        private JsonResponder $json,
    ) {}

    /**
     * `?month=AAAA-MM&documents=invoice,recap` : destinataire, sujet, message (modèle rempli) et pièces jointes à proposer.
     *
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('month', 'documents');
        $month = $input->string('month', 7);
        $documents = $input->enumList('documents', EmailDocument::class);
        if (!BillingMonth::isValid($month)) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();

        $draft = $this->emails->prepare($args['id'], $month, $documents);

        return $this->json->data($response, [
            'to' => $draft->to,
            'firstName' => $draft->firstName,
            'lastName' => $draft->lastName,
            'subject' => $draft->subject,
            'message' => $draft->message,
            'attachments' => array_map(
                static fn(array $a): array => ['document' => $a['document']->value, 'name' => $a['name']],
                $draft->attachments,
            ),
        ]);
    }

    /**
     * `month`, `documents` (tableau), `to`, `subject`, `message` et `copyToMe` (copie à l'adresse de contact de l'entreprise).
     *
     * @param array<string, string> $args
     */
    public function send(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('month', 'documents', 'to', 'subject', 'message', 'copyToMe');
        $month = $input->string('month', 7);
        $documents = $input->enumList('documents', EmailDocument::class);
        $to = $input->email('to');
        $subject = $input->string('subject', 200);
        $message = $input->string('message', 10000);
        $copyToMe = $input->has('copyToMe') ? $input->boolean('copyToMe') : false;
        if (!BillingMonth::isValid($month)) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();

        $this->emails->send($args['id'], $month, $documents, $to, $subject, $message, $copyToMe);

        return $this->json->noContent($response);
    }
}
