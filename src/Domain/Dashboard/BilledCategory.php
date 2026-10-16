<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Invoice\InvoiceLineKind;

/**
 * Catégories du tableau de bord (différentes de celles de la facture) : la conciergerie et le linge sont du **revenu** ;
 * les **débours** sont des sommes avancées puis refacturées, sans marge : le ménage et les rétrocommissions de plateforme
 * (commission Abritel refacturée, et toute ligne `platform_commission` à venir).
 */
enum BilledCategory: string
{
    case Concierge = 'concierge';
    case Linen = 'linen';
    case Disbursement = 'disbursement';

    public static function of(InvoiceLineKind $kind, ExtraType $category): self
    {
        if ($kind === InvoiceLineKind::PlatformCommission) {
            return self::Disbursement;
        }

        return match ($category) {
            ExtraType::Concierge => self::Concierge,
            ExtraType::Linen => self::Linen,
            ExtraType::Cleaning => self::Disbursement,
        };
    }
}
