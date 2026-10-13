<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Taux de TVA **proposés** à la saisie d'une prestation supplémentaire, par type (20 % par défaut, comme Facturiza).
 * Chaque prestation garde son propre taux : changer ces réglages ne modifie aucune prestation existante.
 */
final class AddVatRatesToCompanySettings extends AbstractMigration
{
    public function change(): void
    {
        $rate = ['precision' => 5, 'scale' => 2, 'default' => 20];

        $this->table('company_settings')
            ->addColumn('vat_rate_concierge', 'decimal', $rate)
            ->addColumn('vat_rate_linen', 'decimal', $rate)
            ->addColumn('vat_rate_cleaning', 'decimal', $rate)
            ->update();
    }
}
