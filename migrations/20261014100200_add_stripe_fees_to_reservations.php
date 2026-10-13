<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Frais de paiement lus chez Stripe, mémorisés pour ne pas rappeler Stripe à chaque synchronisation : `stripe_fee` nul = pas
 * encore lu ; `stripe_error` = code de la dernière erreur de lecture (la lecture est alors retentée à la synchro suivante).
 */
final class AddStripeFeesToReservations extends AbstractMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('stripe_fee', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'after' => 'net_owner_revenue'])
            ->addColumn('stripe_error', 'string', ['limit' => 64, 'null' => true, 'after' => 'stripe_fee'])
            ->update();
    }
}
