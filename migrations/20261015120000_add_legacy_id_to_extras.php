<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prestations reprises de Facturiza (`paid_tasks`) : l'identifiant d'origine rend l'import idempotent (relancer ne
 * duplique rien). Nul pour toute prestation saisie dans Casamiza ; les valeurs nulles ne comptent pas dans l'index unique.
 */
final class AddLegacyIdToExtras extends AbstractMigration
{
    public function change(): void
    {
        $this->table('extras')
            ->addColumn('legacy_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'deleted_at'])
            ->addIndex(['tenant_id', 'legacy_id'], ['unique' => true, 'name' => 'uq_extras_tenant_legacy'])
            ->update();
    }
}
