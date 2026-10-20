<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le coût logiciel fixe par logement disparaît : le coût Guesty du tableau de bord vient des factures importées
 * (`guesty_cost_items`), celui de Beyond des relevés.
 */
final class DropSoftwareCostFromCompanySettings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('company_settings')->removeColumn('software_cost')->update();
    }

    public function down(): void
    {
        $this->table('company_settings')
            ->addColumn('software_cost', 'decimal', ['precision' => 8, 'scale' => 2, 'default' => 0])
            ->update();
    }
}
