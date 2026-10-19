<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Affectation manuelle d'un logement à un propriétaire de Casamiza. Faux : la synchronisation Guesty suit `owners[0]`.
 * Vrai : un administrateur a choisi, la synchronisation n'écrit plus `owner_id` (ex. deux entités à facturer qui
 * partagent un logement dans le portail Guesty).
 */
final class AddOwnerManualToProperties extends AbstractMigration
{
    public function change(): void
    {
        $this->table('properties')
            ->addColumn('owner_manual', 'boolean', ['default' => false, 'null' => false, 'after' => 'owner_id'])
            ->update();
    }
}
