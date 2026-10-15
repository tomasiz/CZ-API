<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Company\CompanyService;
use Casamiza\Domain\Company\CompanySettingsPatch;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\CompanySettingsResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final readonly class CompanyController
{
    public function __construct(
        private CompanyService $company,
        private JsonResponder $json,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, CompanySettingsResource::toArray($this->company->get()));
    }

    /** Modification partielle : seuls les champs envoyés changent, une valeur vide efface. */
    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $patch = $this->patch(Input::fromBody($request->getParsedBody()));

        return $this->json->data($response, CompanySettingsResource::toArray($this->company->update($patch)));
    }

    public function logo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->company->logo());

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'private, no-cache');
    }

    /** Envoi d'un logo PNG (formulaire `multipart/form-data`, champ `logo`). */
    public function uploadLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = $request->getUploadedFiles()['logo'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException(['logo' => ['required']]);
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['logo' => ['too_large']]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['logo' => ['invalid_image']]);
        }

        return $this->json->data($response, CompanySettingsResource::toArray($this->company->replaceLogo((string) $file->getStream())));
    }

    public function removeLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, CompanySettingsResource::toArray($this->company->removeLogo()));
    }

    private function patch(Input $input): CompanySettingsPatch
    {
        $input->allowOnly(
            'legalName',
            'legalForm',
            'legalAddress',
            'siret',
            'vatNumber',
            'capital',
            'legalEmail',
            'legalPhone',
            'iban',
            'signatoryFirstName',
            'signatoryLastName',
            'defaultCommission',
            'contactName',
            'contactEmail',
            'emailRecap',
            'emailInvoice',
            'vatRateConcierge',
            'vatRateLinen',
            'vatRateCleaning',
            'softwareCost',
        );

        $siret = $this->normalized($input, 'siret', 20, static fn(string $v): string => (string) preg_replace('/\s+/', '', $v), '/^\d{14}$/');
        $vat = $this->normalized($input, 'vatNumber', 30, static fn(string $v): string => strtoupper((string) preg_replace('/\s+/', '', $v)), '/^[A-Z]{2}[A-Z0-9]{2,12}$/');
        $iban = $this->normalized($input, 'iban', 40, static fn(string $v): string => strtoupper((string) preg_replace('/\s+/', '', $v)), '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/');
        $capital = $this->normalized($input, 'capital', 30, static fn(string $v): string => str_replace(',', '.', (string) preg_replace('/\s+/', '', $v)), '/^\d+(\.\d{1,2})?$/');
        $phone = $this->normalized($input, 'legalPhone', 30, static fn(string $v): string => trim($v), '/^\+?[\d .\-]{9,20}$/');
        $legalEmail = $this->normalized($input, 'legalEmail', 190, static fn(string $v): string => $v, null, true);
        $contactEmail = $this->normalized($input, 'contactEmail', 190, static fn(string $v): string => $v, null, true);

        $setCommission = $input->has('defaultCommission');
        $commission = $setCommission ? $input->optionalNumber('defaultCommission', 0, 100) : null;

        $patch = new CompanySettingsPatch(
            legalName: $this->plain($input, 'legalName', 190),
            legalForm: $this->plain($input, 'legalForm', 50),
            capital: $capital,
            legalAddress: $this->plain($input, 'legalAddress', 500),
            legalEmail: $legalEmail,
            legalPhone: $phone,
            siret: $siret,
            vatNumber: $vat,
            iban: $iban,
            signatoryFirstName: $this->plain($input, 'signatoryFirstName', 100),
            signatoryLastName: $this->plain($input, 'signatoryLastName', 100),
            setDefaultCommission: $setCommission,
            defaultCommission: $commission,
            contactName: $this->plain($input, 'contactName', 190),
            contactEmail: $contactEmail,
            emailRecapTemplate: $this->plain($input, 'emailRecap', 10000, false),
            emailInvoiceTemplate: $this->plain($input, 'emailInvoice', 10000, false),
            vatRateConcierge: $input->optionalNumber('vatRateConcierge', 0, 100),
            vatRateLinen: $input->optionalNumber('vatRateLinen', 0, 100),
            vatRateCleaning: $input->optionalNumber('vatRateCleaning', 0, 100),
            softwareCost: $input->optionalNumber('softwareCost', 0, 1000),
        );
        $input->assertValid();

        return $patch;
    }

    /** Texte libre : null si le champ est absent, sinon sa valeur (vide = effacer). */
    private function plain(Input $input, string $field, int $max, bool $trim = true): ?string
    {
        if (!$input->has($field)) {
            return null;
        }

        return $trim ? $input->text($field, $max) : ($input->optionalString($field, $max, false) ?? '');
    }

    /**
     * Champ au format contrôlé : normalisé puis comparé à un motif (ou à une adresse e-mail) ; vide = effacer.
     *
     * @param callable(string): string $normalize
     */
    private function normalized(Input $input, string $field, int $max, callable $normalize, ?string $pattern, bool $email = false): ?string
    {
        if (!$input->has($field)) {
            return null;
        }
        $value = $normalize($input->text($field, $max));
        if ($value === '') {
            return '';
        }
        $valid = $email ? filter_var($value, FILTER_VALIDATE_EMAIL) !== false : preg_match((string) $pattern, $value) === 1;
        if (!$valid) {
            $input->fail($field, $email ? 'invalid_email' : 'invalid_format');
        }

        return $value;
    }
}
