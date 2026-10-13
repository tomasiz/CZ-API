<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Réservation disparue de Guesty (annulée, ou plus confirmée) : archivée, jamais supprimée, et rétablie si elle revient,
 * comme les propriétaires et les logements.
 */
final class AddArchivedAtToReservations extends AbstractMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('archived_at', 'datetime', ['null' => true, 'after' => 'net_owner_revenue'])
            ->update();
    }
}
