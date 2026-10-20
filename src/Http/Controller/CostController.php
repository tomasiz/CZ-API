<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Cost\BeyondStatementService;
use Casamiza\Http\Resource\BeyondFeeResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/** Coûts logiciels du tenant : relevés de Beyond Pricing, du 12 au 12 (administrateurs seulement, voir les routes). */
final readonly class CostController
{
    /** Taille maximale du CSV (un relevé mensuel pèse quelques centaines de Ko). */
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private BeyondStatementService $beyond,
        private JsonResponder $json,
        private ClockInterface $clock,
    ) {}

    /** Les 12 relevés d'une année (`?year=`, l'année en cours par défaut). */
    public function statements(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $year = $request->getQueryParams()['year'] ?? null;
        if ($year === null) {
            $year = $this->clock->now()->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y');
        }
        if (!is_string($year) || preg_match('/^\d{4}$/', $year) !== 1) {
            throw new ValidationException(['year' => ['invalid_value']]);
        }

        return $this->json->data($response, array_map(BeyondFeeResource::row(...), $this->beyond->year((int) $year)));
    }

    /**
     * Ce que le relevé traité a apporté à chaque réservation.
     *
     * @param array<string, string> $args
     */
    public function statement(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->data($response, BeyondFeeResource::report($this->beyond->detail(self::start($args))));
    }

    /**
     * Dépôt du CSV d'un relevé (multipart : champ `file`, champ `apply` = `true` | `false`). Avec `apply=false`, simulation : même
     * bilan, rien n'est écrit. Un relevé défectueux est refusé en entier (422).
     *
     * @param array<string, string> $args
     */
    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();
        $apply = self::apply(is_array($body) ? ($body['apply'] ?? null) : null);

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

        $report = $this->beyond->import(self::start($args), (string) $file->getStream(), self::sourceName($file), $apply);

        return $this->json->data($response, BeyondFeeResource::report($report));
    }

    /**
     * Rejoue le CSV conservé (JSON : `apply` booléen).
     *
     * @param array<string, string> $args
     */
    public function reprocess(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();
        $report = $this->beyond->reprocess(self::start($args), self::apply(is_array($body) ? ($body['apply'] ?? null) : null));

        return $this->json->data($response, BeyondFeeResource::report($report));
    }

    /**
     * Le CSV conservé, tel qu'il a été déposé.
     *
     * @param array<string, string> $args
     */
    public function file(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->beyond->file(self::start($args));
        $response->getBody()->write($file['contents']);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** `true` / `false` (booléen JSON ou chaîne d'un formulaire multipart). */
    private static function apply(mixed $value): bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            null => throw new ValidationException(['apply' => ['required']]),
            default => throw new ValidationException(['apply' => ['invalid_value']]),
        };
    }

    /**
     * Premier jour du relevé, `AAAA-MM-JJ` dans l'URL ; un autre format ou un jour impossible : relevé introuvable.
     *
     * @param array<string, string> $args
     */
    private static function start(array $args): DateTimeImmutable
    {
        $day = $args['start'] ?? '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new NotFoundException('statement_not_found');
        }

        return $date;
    }

    /** Nom du fichier tel que l'administrateur l'a choisi, débarrassé de ce qui n'a rien à faire dans un libellé. */
    private static function sourceName(UploadedFileInterface $file): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $file->getClientFilename())));

        return mb_substr($name === '' ? 'beyond.csv' : $name, 0, 190);
    }
}
