<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Guesty\GuestySyncService;
use Casamiza\Domain\Reservation\ReservationSyncService;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\SyncReportResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SyncController
{
    public function __construct(
        private GuestySyncService $sync,
        private ReservationSyncService $reservations,
        private JsonResponder $json,
    ) {}

    /**
     * `month` (AAAA-MM, obligatoire) : réimporte de Guesty les réservations **dont le check-out tombe ce mois-là** (UTC, comme
     * `GET /reservations?month=`) et recalcule leurs montants ; une réservation du mois qui a disparu de Guesty est archivée.
     * Les logements doivent déjà avoir été synchronisés (`POST /sync/guesty`). La requête attend la fin et renvoie le bilan.
     */
    public function reservations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('month');
        $month = $input->string('month', 7);
        $from = BillingMonth::isValid($month) ? DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC')) : false;
        if ($from === false) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();
        if ($from === false) {
            throw new ValidationException(['month' => ['invalid_value']]);
        }

        return $this->json->data($response, SyncReportResource::reservations(
            $this->reservations->sync($from, $from->modify('first day of next month')),
        ));
    }

    /** Importe propriétaires et logements depuis Guesty ; la requête attend la fin et renvoie le bilan. */
    public function guesty(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, SyncReportResource::toArray($this->sync->sync()));
    }
}
