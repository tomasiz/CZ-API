<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Cost\GuestyCostService;
use Casamiza\Http\Resource\GuestyCostResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/** Coûts logiciels du tenant : factures de Guesty (export xlsx), administrateurs seulement (voir les routes). */
final readonly class GuestyCostController
{
    /** Taille maximale du fichier (une facture pèse une dizaine de Ko). */
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private GuestyCostService $guesty,
        private JsonResponder $json,
    ) {}

    public function invoices(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(GuestyCostResource::invoice(...), $this->guesty->invoices()));
    }

    /** @param array<string, string> $args */
    public function invoice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->data($response, GuestyCostResource::report($this->guesty->detail(self::number($args))));
    }

    /**
     * Dépôt de l'export xlsx d'une facture (multipart : champ `file`, champ `apply` = `true` | `false`). Le numéro de facture se
     * lit dans le nom du fichier. Avec `apply=false`, simulation : même bilan, rien n'est écrit. Une facture défectueuse est
     * refusée en entier (422).
     */
    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $apply = match (is_array($body) ? ($body['apply'] ?? null) : null) {
            true, 'true' => true,
            false, 'false' => false,
            null => throw new ValidationException(['apply' => ['required']]),
            default => throw new ValidationException(['apply' => ['invalid_value']]),
        };

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException(['file' => ['required']]);
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE || ($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['too_large']]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['invalid_file']]);
        }

        $report = $this->guesty->import(self::sourceName($file), (string) $file->getStream(), $apply);

        return $this->json->data($response, GuestyCostResource::report($report));
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->guesty->delete(self::number($args));

        return $this->json->noContent($response);
    }

    /** @param array<string, string> $args */
    public function file(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->guesty->file(self::number($args));
        $response->getBody()->write($file['contents']);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array<string, string> $args */
    private static function number(array $args): string
    {
        $number = strtoupper($args['number'] ?? '');

        return preg_match('/^INV\d{3,12}$/', $number) === 1 ? $number : throw new NotFoundException('invoice_not_found');
    }

    /** Nom du fichier tel que l'administrateur l'a choisi, débarrassé de ce qui n'a rien à faire dans un libellé. */
    private static function sourceName(UploadedFileInterface $file): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $file->getClientFilename())));

        return mb_substr($name === '' ? 'guesty.xlsx' : $name, 0, 190);
    }
}
