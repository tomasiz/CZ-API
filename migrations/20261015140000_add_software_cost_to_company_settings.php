<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Coût logiciel mensuel **par logement** (HT), utilisé par le tableau de bord pour calculer la marge nette.
 * 0 = non renseigné : le coût n'est alors pas compté.
 */
final class AddSoftwareCostToCompanySettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('company_settings')
            ->addColumn('software_cost', 'decimal', ['precision' => 8, 'scale' => 2, 'default' => 0])
            ->update();
    }
}
