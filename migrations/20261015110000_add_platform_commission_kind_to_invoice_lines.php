<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Commission d'une plateforme (Abritel) avancée par la conciergerie et refacturée au propriétaire : c'est une ligne de
 * plus par réservation, rangée dans la catégorie conciergerie (comme chez Facturiza), qu'il faut distinguer des frais de
 * conciergerie de la même réservation.
 */
final class AddPlatformCommissionKindToInvoiceLines extends AbstractMigration
{
    public function up(): void
    {
        $this->table('invoice_lines')
            ->changeColumn('kind', 'enum', ['values' => ['reservation', 'platform_commission', 'extra']])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_lines')
            ->changeColumn('kind', 'enum', ['values' => ['reservation', 'extra']])
            ->update();
    }
}
