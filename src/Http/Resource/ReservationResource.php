<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Reservation\ReservationFlag;
use Casamiza\Domain\Reservation\ReservationPage;
use Casamiza\Domain\Reservation\ReservationRow;

/** Représentation JSON d'une réservation de la liste et d'une page de résultats. */
final class ReservationResource
{
    /** @return array<string, mixed> */
    public static function page(ReservationPage $page): array
    {
        return [
            'items' => array_map(self::row(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
            // Totaux de toutes les réservations de la recherche, pas seulement de la page.
            'totals' => [
                'totalPaid' => $page->totals->totalPayout,
                'conciergeFee' => $page->totals->conciergeFee,
                'ownerRevenue' => $page->totals->netOwnerRevenue,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function row(ReservationRow $row): array
    {
        $r = $row->reservation;

        return [
            'id' => $r->id,
            'guestyId' => $r->guestyId,
            'ownerId' => $r->ownerId,
            'ownerName' => $row->ownerName,
            'propertyId' => $r->propertyId,
            'property' => $row->propertyName,
            'guest' => $r->guest,
            'platform' => $r->platform->value,
            // Vide pour Abritel, Manuel et Site internet.
            'platformRef' => $r->platformRef === '' ? null : $r->platformRef,
            'checkIn' => $r->checkIn->format('c'),
            'checkOut' => $r->checkOut->format('c'),
            'nights' => $r->nights,
            'guests' => $r->guests,
            'totalPaid' => $r->totalPayout,
            'platformCommission' => $r->channelCommission,
            'paymentFees' => $r->paymentFee,
            'touristTax' => $r->touristTax,
            'cleaningFee' => $r->cleaningFee,
            'netToOwner' => $r->netPayout,
            'conciergeFee' => $r->conciergeFee,
            'ownerRevenue' => $r->netOwnerRevenue,
            // Données à relire avant de facturer : champ (snake_case) et code du motif, traduits par le front.
            'flags' => array_map(
                static fn(ReservationFlag $flag): array => ['field' => $flag->field->value, 'reason' => $flag->reason->value],
                $r->flags,
            ),
        ];
    }
}
