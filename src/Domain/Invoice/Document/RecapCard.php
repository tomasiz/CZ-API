<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/**
 * Une réservation du récapitulatif. `retrocommission` (Abritel, site internet, manuel) change l'ordre et le libellé des
 * lignes : la plateforme n'a rien prélevé, le propriétaire se voit retirer la commission après le net versé.
 */
final readonly class RecapCard
{
    public function __construct(
        public string $title,
        public string $subtitle,
        public bool $retrocommission,
        public float $totalPaid,
        public float $channelCommission,
        public float $paymentFee,
        public float $touristTax,
        public float $netPayout,
        public float $cleaningFee,
        public float $conciergeFee,
        public float $netOwnerRevenue,
    ) {}
}
