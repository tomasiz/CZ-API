<?php

declare(strict_types=1);

namespace Casamiza\Tests\Mail;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** E-mail de la facture et du récapitulatif : `GET` et `POST /owners/{id}/email` (administrateurs seulement). */
final class DocumentEmailTest extends AuthTestCase
{
    private const TEMPLATE = "Bonjour {first_name} {last_name},\n\nFacture de {doc_month} {doc_year} : {doc_total}.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', 'alice.martin@example.test', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '', 48.0, 17.0, null));
        $this->reservation('res-1', '2026-09-02', '2026-09-05');
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre Propriétaire', 'Autre', 'Propriétaire', 'autre@example.test', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $checkIn, string $checkOut): void
    {
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L1',
            'airbnb2',
            [],
            '',
            'Voyageur',
            new DateTimeImmutable($checkIn . ' 14:00:00 UTC'),
            new DateTimeImmutable($checkOut . ' 08:00:00 UTC'),
            3,
            2,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            [],
            [],
        ), 'owner-1', 'prop-1')->withCalculation(new CalculatedAmounts(500.0, 50.0, 5.0, 12.0, 48.0, 433.0, 27.86, 300.0, [])));
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, '/api/v1' . $path, $this->bearer($this->accessToken($email)), $body);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    private function configureCompany(string $invoiceTemplate = self::TEMPLATE): void
    {
        $response = $this->call('PATCH', '/settings/company', [
            'contactName' => 'Casamea Conciergerie',
            'contactEmail' => 'contact@casamea.test',
            'emailInvoice' => $invoiceTemplate,
            'emailRecap' => 'Récap {doc_month} pour {first_name}',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function connectMailerSend(): void
    {
        $this->thirdParty->append(new Response(200, [], '{"data":[]}'));
        $response = $this->call('PUT', '/settings/integrations/mailersend', ['apiKey' => 'mlsn.secret-key', 'templateId' => 'tpl-42']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function issueInvoice(): void
    {
        self::assertSame(201, $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09'])->getStatusCode());
    }

    private function lastRequest(): RequestInterface
    {
        $calls = array_values($this->thirdPartyCalls->getArrayCopy());
        self::assertNotSame([], $calls);

        return $calls[count($calls) - 1]['request'];
    }

    /** @return array<string, mixed> corps JSON de la dernière requête envoyée au service tiers */
    private function lastPayload(): array
    {
        $payload = json_decode((string) $this->lastRequest()->getBody(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function sendBody(string $documents = 'invoice,recap'): array
    {
        return [
            'month' => '2026-09',
            'documents' => explode(',', $documents),
            'to' => 'alice.martin@example.test',
            'subject' => 'Facture septembre 2026',
            'message' => "Bonjour Alice,\n\nVoici la facture.",
            'copyToMe' => false,
        ];
    }

    public function testManagersAndVisitorsCannotPrepareOrSend(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/owners/owner-1/email?month=2026-09&documents=recap')->getStatusCode());
        foreach ([['GET', '/owners/owner-1/email?month=2026-09&documents=recap', null], ['POST', '/owners/owner-1/email', $this->sendBody()]] as [$method, $path, $body]) {
            $response = $this->call($method, $path, $body, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method);
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertCount(0, $this->thirdPartyCalls);
    }

    public function testPreparationFillsTheCompanyTemplateAndNamesTheAttachments(): void
    {
        $this->configureCompany();
        $this->issueInvoice();

        $draft = $this->data($this->call('GET', '/owners/owner-1/email?month=2026-09&documents=recap,invoice'));

        self::assertSame('alice.martin@example.test', $draft['to']);
        self::assertSame('Facture et récapitulatif septembre 2026', $draft['subject']);
        // Facture de 27,86 € TTC de conciergerie + 17 € de linge + 48 € de ménage
        self::assertSame("Bonjour Alice Martin,\n\nFacture de septembre 2026 : 92,86.", $draft['message']);
        self::assertSame(
            [['document' => 'invoice', 'name' => 'Facture FZ0000001 Alice Martin - septembre 2026.pdf'], ['document' => 'recap', 'name' => 'Récapitulatif Alice Martin - septembre 2026.pdf']],
            $draft['attachments'],
            'La facture vient toujours avant le récapitulatif, quel que soit l’ordre demandé.',
        );
    }

    public function testASingleDocumentChoosesItsSubjectAndTemplate(): void
    {
        $this->configureCompany();
        $this->issueInvoice();

        $recap = $this->data($this->call('GET', '/owners/owner-1/email?month=2026-09&documents=recap'));
        self::assertSame(['Récapitulatif septembre 2026', 'Récap septembre pour Alice'], [$recap['subject'], $recap['message']]);

        $invoice = $this->data($this->call('GET', '/owners/owner-1/email?month=2026-09&documents=invoice'));
        self::assertSame('Facture septembre 2026', $invoice['subject']);
    }

    public function testWithoutATemplateTheDefaultMessageIsUsed(): void
    {
        $this->call('PATCH', '/settings/company', ['contactName' => 'Casamea', 'contactEmail' => 'contact@casamea.test']);
        $this->issueInvoice();

        $draft = $this->data($this->call('GET', '/owners/owner-1/email?month=2026-09&documents=invoice,recap'));

        self::assertStringStartsWith("Bonjour Alice,\n\nVeuillez trouver ci-joint la facture du mois de septembre 2026", (string) $draft['message']);
        self::assertStringContainsString("d'un montant total de 92,86 € TTC", (string) $draft['message']);
        self::assertStringContainsString('récapitulatif de vos locations du mois de septembre 2026', (string) $draft['message']);
        self::assertStringEndsWith("Bien cordialement,\nCasamea", (string) $draft['message']);
    }

    public function testWithoutAnInvoiceOnlyTheRecapCanBeSent(): void
    {
        $this->configureCompany();

        $recap = $this->data($this->call('GET', '/owners/owner-1/email?month=2026-09&documents=recap'));
        self::assertSame([['document' => 'recap', 'name' => 'Récapitulatif Alice Martin - septembre 2026.pdf']], $recap['attachments']);

        $invoice = $this->call('GET', '/owners/owner-1/email?month=2026-09&documents=invoice,recap');
        self::assertSame(404, $invoice->getStatusCode());
        self::assertSame('invoice_not_found', $this->errorCode($invoice));
    }

    public function testSendingPostsTheFilledTemplateAndBothPdfToMailerSend(): void
    {
        $this->configureCompany();
        $this->issueInvoice();
        $this->connectMailerSend();
        $this->thirdParty->append(new Response(202, ['X-Message-Id' => 'm-1']));

        $response = $this->call('POST', '/owners/owner-1/email', [...$this->sendBody(), 'message' => "Bonjour <b>Alice</b>,\n\nVoici & merci.\nhttps://app.test/x?a=1"]);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('/v1/email', $this->thirdPartyPaths()[1]);
        self::assertSame('Bearer mlsn.secret-key', $this->lastRequest()->getHeaderLine('Authorization'));

        $payload = $this->lastPayload();
        self::assertSame(['email' => 'contact@casamea.test', 'name' => 'Casamea Conciergerie'], $payload['from']);
        self::assertSame([['email' => 'alice.martin@example.test', 'name' => 'Alice Martin']], $payload['to']);
        self::assertSame(['Facture septembre 2026', 'tpl-42'], [$payload['subject'], $payload['template_id']]);
        self::assertArrayNotHasKey('bcc', $payload);
        // Le message saisi est échappé, ses adresses web deviennent des liens et ses retours à la ligne des <br />
        self::assertSame(
            [['email' => 'alice.martin@example.test', 'data' => ['text' => 'Bonjour &lt;b&gt;Alice&lt;/b&gt;,<br /><br />Voici &amp; merci.<br /><a href="https://app.test/x?a=1">https://app.test/x?a=1</a>']]],
            $payload['personalization'],
        );
        self::assertSame(
            [
                ['content' => base64_encode('%PDF-fake invoice FZ0000001'), 'filename' => 'Facture FZ0000001 Alice Martin - septembre 2026.pdf', 'disposition' => 'attachment'],
                ['content' => base64_encode('%PDF-fake recap'), 'filename' => 'Récapitulatif Alice Martin - septembre 2026.pdf', 'disposition' => 'attachment'],
            ],
            $payload['attachments'],
        );

        // L'envoi est tracé (quoi, de quel mois), sans l'adresse du destinataire ni le message.
        $sent = $this->audit->of(\Casamiza\Domain\Audit\AuditAction::InvoiceEmailed);
        self::assertCount(1, $sent);
        self::assertSame(['invoice', 'FZ0000001', 'user-admin'], [$sent[0]->targetType, $sent[0]->targetLabel, $sent[0]->actorUserId]);
        self::assertSame(['invoice', 'recap'], $sent[0]->context['documents']);
        self::assertStringNotContainsString('alice.martin@example.test', json_encode($sent[0], JSON_THROW_ON_ERROR));
    }

    public function testRefusedSendsAreNotTraced(): void
    {
        $this->issueInvoice();
        $this->configureCompany();

        $this->call('POST', '/owners/owner-1/email', $this->sendBody());

        self::assertSame([], $this->audit->of(\Casamiza\Domain\Audit\AuditAction::InvoiceEmailed));
    }

    public function testACopyGoesToTheCompanyContactAsBlindCopy(): void
    {
        $this->configureCompany();
        $this->issueInvoice();
        $this->connectMailerSend();
        $this->thirdParty->append(new Response(202));

        $this->call('POST', '/owners/owner-1/email', [...$this->sendBody('invoice'), 'copyToMe' => true]);

        self::assertSame([['email' => 'contact@casamea.test', 'name' => 'Casamea Conciergerie']], $this->lastPayload()['bcc']);
    }

    public function testARecapOfAMonthWithoutInvoiceIsBuiltOnTheFly(): void
    {
        $this->configureCompany();
        $this->connectMailerSend();
        $this->thirdParty->append(new Response(202));

        $response = $this->call('POST', '/owners/owner-1/email', $this->sendBody('recap'));

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $attachments = $this->lastPayload()['attachments'];
        self::assertIsArray($attachments);
        self::assertCount(1, $attachments);
        self::assertSame(base64_encode('%PDF-fake recap'), $attachments[0]['content']);
        self::assertSame([], $this->invoices->rows, 'Envoyer un récapitulatif ne crée aucune facture.');
    }

    public function testRefusalsAreCoded(): void
    {
        $this->issueInvoice();

        // MailerSend n'est pas configuré
        $this->configureCompany();
        $notConfigured = $this->call('POST', '/owners/owner-1/email', $this->sendBody());
        self::assertSame(404, $notConfigured->getStatusCode());
        self::assertSame('integration_not_configured', $this->errorCode($notConfigured));

        $this->connectMailerSend();
        foreach ([[401, 422, 'integration_invalid_credentials'], [403, 422, 'integration_forbidden'], [422, 422, 'email_rejected'], [429, 429, 'integration_rate_limited'], [500, 503, 'integration_unreachable']] as [$provider, $status, $code]) {
            $this->thirdParty->append(new Response($provider, [], '{"message":"secret detail"}'));
            $response = $this->call('POST', '/owners/owner-1/email', $this->sendBody());
            self::assertSame($status, $response->getStatusCode(), (string) $provider);
            self::assertSame($code, $this->errorCode($response));
            self::assertStringNotContainsString('secret detail', (string) $response->getBody());
        }
    }

    public function testTheCompanyContactIsRequiredToSend(): void
    {
        $this->issueInvoice();
        $this->connectMailerSend();

        $response = $this->call('POST', '/owners/owner-1/email', $this->sendBody());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('contact_email_missing', $this->errorCode($response));
        self::assertCount(1, $this->thirdPartyCalls, 'Seul le contrôle de la clé a été appelé.');
    }

    public function testInputIsValidated(): void
    {
        $this->configureCompany();
        $this->connectMailerSend();

        foreach ([
            ['to', 'pas-un-email'],
            ['subject', ''],
            ['message', ''],
            ['documents', ['facture']],
            ['documents', []],
            ['month', '2026-9'],
            ['copyToMe', 'oui'],
            ['tenantId', 'tenant-b'],
        ] as [$field, $value]) {
            $response = $this->call('POST', '/owners/owner-1/email', [...$this->sendBody(), $field => $value]);
            self::assertSame(422, $response->getStatusCode(), $field);
            self::assertSame('validation_failed', $this->errorCode($response), $field);
        }
        self::assertSame('validation_failed', $this->errorCode($this->call('GET', '/owners/owner-1/email?month=2026-09')));
        self::assertCount(1, $this->thirdPartyCalls);
    }

    public function testAnOwnerOfAnotherTenantIsNotFound(): void
    {
        $this->configureCompany();
        $this->connectMailerSend();

        $send = $this->call('POST', '/owners/owner-b/email', $this->sendBody());
        $prepare = $this->call('GET', '/owners/owner-b/email?month=2026-09&documents=recap');

        foreach ([$send, $prepare] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('owner_not_found', $this->errorCode($response));
        }
        self::assertCount(1, $this->thirdPartyCalls);
    }

    public function testTheKeyIsCheckedWhenSavedAndNeverReturned(): void
    {
        $this->thirdParty->append(new Response(401, [], '{}'));
        $refused = $this->call('PUT', '/settings/integrations/mailersend', ['apiKey' => 'mlsn.bad', 'templateId' => 'tpl-1']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('integration_invalid_credentials', $this->errorCode($refused));
        self::assertArrayNotHasKey('tenant-a|mailersend', $this->integrations->rows);

        // Une clé limitée à l'envoi ne peut pas lire les domaines (403) mais reste valable
        $this->thirdParty->append(new Response(403, [], '{}'));
        $saved = $this->call('PUT', '/settings/integrations/mailersend', ['apiKey' => 'mlsn.send-only', 'templateId' => 'tpl-1']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertStringNotContainsString('mlsn.send-only', (string) $saved->getBody());
        self::assertStringNotContainsString('mlsn.send-only', (string) json_encode($this->integrations->rows['tenant-a|mailersend']));
    }
}
