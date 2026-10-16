<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\ReservationQuery;
use Casamiza\Domain\Reservation\ReservationService;
use Casamiza\Domain\Reservation\ReservationSort;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\ReservationResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ReservationController
{
    public function __construct(
        private ReservationService $reservations,
        private JsonResponder $json,
    ) {}

    /**
     * Liste paginée. Paramètres (tous facultatifs) : `month` (AAAA-MM, mois du check-out ; absent = tous les mois),
     * `ownerId` et `propertyId` (identifiants séparés par des virgules), `platform` (valeurs séparées par des virgules),
     * `flagged` (`flagged` ou `ok`), `q` (texte libre), `sort`, `order` (`asc` / `desc`), `page`, `perPage`.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('month', 'ownerId', 'propertyId', 'platform', 'flagged', 'q', 'sort', 'order', 'page', 'perPage');

        $month = $input->optionalString('month', 7);
        $from = $before = null;
        if ($month !== null) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
                [$from, $before] = BillingMonth::bounds($month);
            } else {
                $input->fail('month');
            }
        }

        $platforms = [];
        foreach ($input->stringList('platform', 10, 20) as $value) {
            $platform = Platform::tryFrom($value);
            if ($platform === null) {
                $input->fail('platform', 'invalid_value');
                break;
            }
            $platforms[] = $platform;
        }
        $flaggedValue = $input->optionalString('flagged', 10);
        if ($flaggedValue !== null && $flaggedValue !== 'flagged' && $flaggedValue !== 'ok') {
            $input->fail('flagged', 'invalid_value');
        }
        $flagged = $flaggedValue === null ? null : $flaggedValue === 'flagged';
        $sort = $input->optionalEnum('sort', ReservationSort::class) ?? ReservationSort::CheckOut;
        $order = $input->optionalString('order', 4) ?? 'asc';
        if ($order !== 'asc' && $order !== 'desc') {
            $input->fail('order', 'invalid_value');
        }

        $query = new ReservationQuery(
            checkOutFrom: $from,
            checkOutBefore: $before,
            ownerIds: $input->stringList('ownerId'),
            propertyIds: $input->stringList('propertyId'),
            platforms: $platforms,
            flagged: $flagged,
            search: $input->text('q', 100),
            sort: $sort,
            descending: $order === 'desc',
            page: $input->integer('page', 1, 100_000, 1),
            perPage: $input->integer('perPage', 1, 500, 50),
        );
        $input->assertValid();

        return $this->json->data($response, ReservationResource::page($this->reservations->search($query)));
    }
}
