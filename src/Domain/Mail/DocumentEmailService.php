<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceService;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\UnprocessableException;
use Psr\Log\LoggerInterface;

/**
 * E-mail de la facture et/ou du récapitulatif d'un propriétaire pour un mois. Le message vient des modèles de la
 * conciergerie (réglages Entreprise) ; l'administrateur le relit, le corrige puis l'envoie, synchrone.
 *
 * - Avec une facture émise pour le mois, les deux PDF sont ceux qui ont été figés à l'émission.
 * - Sans facture, seul le récapitulatif existe : il est fabriqué à l'instant, comme pour son aperçu.
 *
 * Le français de l'e-mail (sujet, texte, noms de fichiers) est du contenu destiné au propriétaire, pas un message de l'API.
 */
final readonly class DocumentEmailService
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private InvoiceService $invoices,
        private OwnerRepositoryInterface $owners,
        private CompanySettingsRepositoryInterface $company,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private AuditLogInterface $audit,
    ) {}

    /**
     * Destinataire, sujet, message (modèle rempli) et noms des pièces jointes.
     *
     * @param list<EmailDocument> $documents
     * @throws NotFoundException `owner_not_found`, `invoice_not_found` (la facture est demandée mais n'existe pas pour ce mois)
     * @throws UnprocessableException `month_not_billable` (mois invalide), `nothing_to_bill` (aucun document demandé)
     */
    public function prepare(string $ownerId, string $month, array $documents): EmailDraft
    {
        [$owner, $invoice, $documents] = $this->resolve($ownerId, $month, $documents);
        $settings = $this->company->find() ?? CompanySettings::blank();
        [$year, $monthNumber] = explode('-', $month);
        $monthName = self::MONTHS[(int) $monthNumber - 1];
        $withInvoice = in_array(EmailDocument::Invoice, $documents, true);

        $template = $withInvoice ? $settings->emailInvoiceTemplate : $settings->emailRecapTemplate;
        if (trim($template) === '') {
            $template = $this->defaultTemplate($documents, $settings);
        }

        $message = strtr($template, [
            '{first_name}' => $owner->firstName,
            '{last_name}' => $owner->lastName,
            '{doc_total}' => $invoice === null ? '' : $this->euros($invoice->amountTtc),
            '{doc_month}' => $monthName,
            '{doc_year}' => $year,
        ]);

        $subject = match (true) {
            count($documents) === 2 => 'Facture et récapitulatif',
            $withInvoice => 'Facture',
            default => 'Récapitulatif',
        } . ' ' . $monthName . ' ' . $year;

        $attachments = array_map(
            fn(EmailDocument $document): array => ['document' => $document, 'name' => $this->fileName($document, $owner, $invoice, $monthName, $year)],
            $documents,
        );

        return new EmailDraft($owner->email, $owner->firstName, $owner->lastName, $subject, $message, $attachments);
    }

    /**
     * Envoie le message (relu par l'administrateur) avec les documents en pièces jointes, depuis l'adresse de contact de
     * l'entreprise. `$copyToMe` ajoute cette même adresse en copie cachée.
     *
     * @param list<EmailDocument> $documents
     * @throws NotFoundException `owner_not_found`, `invoice_not_found`, `integration_not_configured`
     * @throws UnprocessableException `month_not_billable`, `contact_email_missing`, `nothing_to_bill` (récapitulatif d'un mois vide), `email_rejected`
     * @throws \Casamiza\Shared\Exception\ApiException le fournisseur a refusé ou n'a pas répondu (voir MailerInterface)
     */
    public function send(string $ownerId, string $month, array $documents, string $to, string $subject, string $message, bool $copyToMe): void
    {
        $settings = $this->company->find() ?? CompanySettings::blank();
        if (trim($settings->contactEmail) === '') {
            throw new UnprocessableException('contact_email_missing');
        }
        [$owner, $invoice, $documents] = $this->resolve($ownerId, $month, $documents);
        [$year, $monthNumber] = explode('-', $month);
        $monthName = self::MONTHS[(int) $monthNumber - 1];

        $attachments = [];
        foreach ($documents as $document) {
            $attachments[] = new MailAttachment(
                $this->fileName($document, $owner, $invoice, $monthName, $year),
                match (true) {
                    $invoice === null => $this->invoices->draftRecap($owner->id, $month),
                    default => $this->invoices->pdf($invoice->id, $document === EmailDocument::Recap),
                },
            );
        }

        $contactName = trim($settings->contactName) === '' ? $settings->contactEmail : $settings->contactName;
        $this->mailer->send(new OutgoingEmail(
            $settings->contactEmail,
            $contactName,
            $to,
            $owner->name,
            $subject,
            $message,
            $attachments,
            $copyToMe ? $settings->contactEmail : null,
        ));

        // Dans le journal d'audit : quoi et à qui (le propriétaire), jamais l'adresse ni le contenu.
        $this->audit->record(AuditAction::InvoiceEmailed, $invoice === null ? 'owner' : 'invoice', $invoice === null ? $owner->id : $invoice->id, $invoice === null ? $owner->name : Invoice::formatNumber($invoice->number), [
            'ownerId' => $owner->id,
            'owner' => $owner->name,
            'month' => $month,
            'documents' => array_map(static fn(EmailDocument $d): string => $d->value, $documents),
        ]);

        // Ni adresse ni contenu dans les logs : identifiants seulement.
        $this->logger->info('Document e-mail sent.', [
            'owner_id' => $owner->id,
            'month' => $month,
            'invoice_id' => $invoice?->id,
            'documents' => array_map(static fn(EmailDocument $d): string => $d->value, $documents),
            'copy' => $copyToMe,
        ]);
    }

    /**
     * @param list<EmailDocument> $documents
     * @return array{Owner, ?Invoice, non-empty-list<EmailDocument>} documents dédoublonnés, la facture toujours avant le récapitulatif
     */
    private function resolve(string $ownerId, string $month, array $documents): array
    {
        if (!BillingMonth::isValid($month)) {
            throw new UnprocessableException('month_not_billable');
        }
        $owner = $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
        $invoice = $this->invoices->activeFor($ownerId, $month);
        $ordered = array_values(array_filter(
            [EmailDocument::Invoice, EmailDocument::Recap],
            static fn(EmailDocument $document): bool => in_array($document, $documents, true),
        ));
        if ($ordered === []) {
            throw new UnprocessableException('nothing_to_bill');
        }
        if ($invoice === null && in_array(EmailDocument::Invoice, $ordered, true)) {
            throw new NotFoundException('invoice_not_found');
        }

        return [$owner, $invoice, $ordered];
    }

    private function fileName(EmailDocument $document, Owner $owner, ?Invoice $invoice, string $monthName, string $year): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\\\\\/:*?"<>|]/', '', $owner->name)));

        return $document === EmailDocument::Invoice && $invoice !== null
            ? sprintf('Facture %s %s - %s %s.pdf', $invoice->displayNumber(), $name, $monthName, $year)
            : sprintf('Récapitulatif %s - %s %s.pdf', $name, $monthName, $year);
    }

    /**
     * « 1234,56 » : le nombre seul, sans « € » ni séparateur de milliers. Les modèles de Facturiza écrivent eux-mêmes
     * « {doc_total} € TTC ».
     */
    private function euros(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }

    /**
     * Modèle utilisé tant que l'entreprise n'a pas écrit le sien.
     *
     * @param non-empty-list<EmailDocument> $documents
     */
    private function defaultTemplate(array $documents, CompanySettings $settings): string
    {
        $invoice = "la facture du mois de {doc_month} {doc_year} d'un montant total de {doc_total} € TTC";
        $recap = 'le récapitulatif de vos locations du mois de {doc_month} {doc_year}';
        $body = match (true) {
            count($documents) === 2 => "Veuillez trouver ci-joint {$invoice}.\nVous trouverez également {$recap}.",
            $documents[0] === EmailDocument::Invoice => "Veuillez trouver ci-joint {$invoice}.",
            default => "Veuillez trouver ci-joint {$recap}.",
        };

        return "Bonjour {first_name},\n\n{$body}\n\nVous souhaitant bonne réception.\n\nBien cordialement,\n" . $settings->contactName;
    }
}
