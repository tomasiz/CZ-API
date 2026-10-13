<?php

declare(strict_types=1);

namespace Casamiza\Domain\Extra;

/** Taux de TVA (en %) **proposés** pour chaque type de prestation ; la prestation garde le sien une fois saisie. */
final readonly class VatRates
{
    public const STANDARD = 20.0;

    public function __construct(
        public float $concierge = self::STANDARD,
        public float $linen = self::STANDARD,
        public float $cleaning = self::STANDARD,
    ) {}

    public function for(ExtraType $type): float
    {
        return match ($type) {
            ExtraType::Concierge => $this->concierge,
            ExtraType::Linen => $this->linen,
            ExtraType::Cleaning => $this->cleaning,
        };
    }
}
