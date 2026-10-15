<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Valeur brute du champ `active` de Guesty pour le propriétaire (faux = portail propriétaire non utilisé, d'après les données lues).
 * Elle ne suffit plus à archiver : un propriétaire inactif qui a encore un logement actif reste actif dans Casamiza.
 */
final class AddGuestyActiveToOwners extends AbstractMigration
{
    public function change(): void
    {
        $this->table('owners')
            ->addColumn('guesty_active', 'boolean', ['default' => true, 'null' => false, 'after' => 'commission'])
            ->update();
    }
}
