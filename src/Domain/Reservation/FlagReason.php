<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Motif d'un signalement : un code (jamais une phrase), traduit en français par le front. */
enum FlagReason: string
{
    /** Guesty n'a rien fourni (vide ou 0) pour une donnée attendue. */
    case EmptyOrZeroFromGuesty = 'empty_or_zero_from_guesty';
    case StripeError = 'stripe_error';
    /** Booking.com : une taxe est renseignée alors que la taxe de séjour n'y figure normalement pas. */
    case PossibleVat = 'possible_vat';
    /** Taxe de séjour recalculée en pourcentage de la base d'hébergement. */
    case TaxComputedPercent = 'tax_computed_percent';
    /** Taxe de séjour recalculée par voyageur et par nuit. */
    case TaxComputedPerGuestNight = 'tax_computed_per_guest_night';
    /** Frais de paiement Booking.com estimés à 1,4 % du total payé (aucun paiement exploitable). */
    case PaymentFeeComputed1_4Percent = 'payment_fee_computed_1_4_percent';
    /** Frais de conciergerie nuls alors que le propriétaire a une commission. */
    case ConciergeFeeZero = 'concierge_fee_zero';
    /** Ménage facturé au voyageur différent du coût de ménage + du coût de linge du logement. */
    case CleaningFeeMismatch = 'cleaning_fee_mismatch';
}
