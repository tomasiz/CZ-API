<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Mail;

use Casamiza\Domain\Integration\CheckFailure;
use Casamiza\Domain\Integration\IntegrationService;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Mail\MailAttachment;
use Casamiza\Domain\Mail\MailerInterface;
use Casamiza\Domain\Mail\OutgoingEmail;
use Casamiza\Infrastructure\Integration\MailerSendProvider;
use Casamiza\Shared\Exception\UnprocessableException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Envoi par l'API MailerSend avec la clé et le modèle du tenant : le message est la variable `text` du modèle,
 * avec ses retours à la ligne en `<br />`. Une seule requête, copie cachée comprise.
 *
 * @see https://developers.mailersend.com/api/v1/email.html
 */
final readonly class MailerSendMailer implements MailerInterface
{
    public function __construct(
        private ClientInterface $http,
        private IntegrationService $integrations,
    ) {}

    public function send(OutgoingEmail $email): void
    {
        $values = $this->integrations->values(Provider::MailerSend->value);

        $payload = [
            'from' => ['email' => $email->fromEmail, 'name' => $email->fromName],
            'to' => [['email' => $email->toEmail, 'name' => $email->toName]],
            'subject' => $email->subject,
            'template_id' => $values['templateId'] ?? '',
            'personalization' => [['email' => $email->toEmail, 'data' => ['text' => $this->html($email->text)]]],
            'attachments' => array_map(
                static fn(MailAttachment $a): array => ['content' => base64_encode($a->contents), 'filename' => $a->filename, 'disposition' => 'attachment'],
                $email->attachments,
            ),
        ];
        if ($email->copyEmail !== null) {
            $payload['bcc'] = [['email' => $email->copyEmail, 'name' => $email->fromName]];
        }

        try {
            $response = $this->http->request('POST', MailerSendProvider::BASE_URI . '/v1/email', [
                'json' => $payload,
                'headers' => ['Authorization' => 'Bearer ' . ($values['apiKey'] ?? ''), 'Accept' => 'application/json'],
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 30,
            ]);
        } catch (GuzzleException) {
            throw CheckFailure::Unreachable->toException();
        }

        $status = $response->getStatusCode();
        if ($status === 202 || $status === 200) {
            return;
        }

        throw match (true) {
            $status === 401 => CheckFailure::InvalidCredentials->toException(),
            $status === 403 => CheckFailure::Forbidden->toException(),
            $status === 429 => CheckFailure::RateLimited->toException(),
            // Adresse d'expéditeur non vérifiée, destinataire invalide, modèle inconnu…
            $status === 422 => new UnprocessableException('email_rejected'),
            default => CheckFailure::Unreachable->toException(),
        };
    }

    /**
     * Le texte saisi est échappé (rien de ce qu'écrit l'administrateur n'est du HTML), ses adresses web deviennent des liens
     * cliquables et ses retours à la ligne des `<br />`.
     */
    private function html(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = (string) preg_replace('~https?://[^\s<]+~', '<a href="$0">$0</a>', $escaped);

        return str_replace(["\r\n", "\r", "\n"], '<br />', $linked);
    }
}
