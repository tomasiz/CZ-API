<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Donnée financière d'une réservation qui peut être signalée (à relire à la main avant de facturer). */
enum FinancialField: string
{
    case TotalPayout = 'total_payout';
    case ChannelCommission = 'channel_commission';
    case PaymentFee = 'payment_fee';
    case TouristTax = 'tourist_tax';
    case CleaningFee = 'cleaning_fee';
    case NetPayout = 'net_payout';
    case ConciergeFee = 'concierge_fee';
    case NetOwnerRevenue = 'net_owner_revenue';
}
