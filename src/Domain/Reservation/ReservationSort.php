<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Colonnes sur lesquelles la liste des réservations peut être triée (liste blanche : jamais un nom de colonne reçu tel quel). */
enum ReservationSort: string
{
    case CheckIn = 'checkIn';
    case CheckOut = 'checkOut';
    case Owner = 'owner';
    case Property = 'property';
    case Guest = 'guest';
    case TotalPaid = 'totalPaid';
    case ConciergeFee = 'conciergeFee';
    case OwnerRevenue = 'ownerRevenue';
}
