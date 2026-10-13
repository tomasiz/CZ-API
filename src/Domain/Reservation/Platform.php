<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/**
 * Plateforme d'une réservation, déduite de la valeur brute `source` de Guesty. Valeurs relevées sur les vraies données
 * (`guesty:sources`, FACTURIZA.md §11.B.1) : `Booking.com`, `airbnb2`, `Abritel`, `HomeAway NL`, `website`, `manual`,
 * `Direct`, `owner`, `owner-guest`. La casse varie : la comparaison se fait en minuscules, par « contient ».
 */
enum Platform: string
{
    case Booking = 'booking';
    case Airbnb = 'airbnb';
    case Abritel = 'abritel';
    case Website = 'website';
    case Manual = 'manual';
    case Direct = 'direct';
    /** Séjour propriétaire : conservé (pour l'écran) mais tous montants à 0 et ignoré dans les PDF. */
    case Owner = 'owner';

    public static function fromGuestySource(string $source): self
    {
        $source = mb_strtolower($source);

        return match (true) {
            str_contains($source, 'owner') => self::Owner,
            str_contains($source, 'booking') => self::Booking,
            str_contains($source, 'airbnb') => self::Airbnb,
            str_contains($source, 'homeaway'), str_contains($source, 'abritel') => self::Abritel,
            str_contains($source, 'website') => self::Website,
            str_contains($source, 'manual') => self::Manual,
            default => self::Direct,
        };
    }

    /**
     * Formule « rétrocommission » (la commission plateforme et la taxe de séjour sont retirées après les frais de
     * conciergerie) plutôt que « classique ». `direct` reste classique, comme dans Facturiza.
     */
    public function isRetrocommission(): bool
    {
        return match ($this) {
            self::Abritel, self::Website, self::Manual => true,
            default => false,
        };
    }
}
