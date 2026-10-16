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
     * `month` (AAAA-MM, obligatoire) : réimporte de Guesty les réservations **dont le check-out tombe ce mois-là** (heure de Paris, comme
     * `GET /reservations?month=`) et recalcule leurs montants ; une réservation du mois qui a disparu de Guesty est archivée.
     * Les logements doivent déjà avoir été synchronisés (`POST /sync/guesty`). La requête attend la fin et renvoie le bilan.
     */
    public function reservations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('month');
        $month = $input->string('month', 7);
        $valid = BillingMonth::isValid($month);
        if (!$valid) {
            $input->fail('month', 'invalid_value');
        }
        $input->assertValid();
        if (!$valid) {
            throw new ValidationException(['month' => ['invalid_value']]);
        }

        [$from, $before] = BillingMonth::bounds($month);

        return $this->json->data($response, SyncReportResource::reservations($this->reservations->sync($from, $before)));
    }

    /** Importe propriétaires et logements depuis Guesty ; la requête attend la fin et renvoie le bilan. */
    public function guesty(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, SyncReportResource::toArray($this->sync->sync()));
    }
}
