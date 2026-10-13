<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Réservations confirmées de Guesty (rattachées au mois de leur check-out) et leurs signalements.
 *
 * Colonnes brutes : valeurs de Guesty, gardées pour recalculer sans le rappeler. Colonnes calculées : résultat des
 * corrections par plateforme et de la formule classique ou rétrocommission (le mode se déduit de `platform`, pas de colonne).
 * Les dates sont en UTC.
 */
final class CreateReservations extends AbstractMigration
{
    public function change(): void
    {
        // Cible de la clé étrangère composite (tenant_id, property_id) ci-dessous.
        $this->table('properties')
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_properties_tenant_id'])
            ->update();

        $money = ['precision' => 12, 'scale' => 2, 'default' => 0];

        $this->table('reservations', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('guesty_id', 'string', ['limit' => 64])
            // Nul si le logement n'a pas de propriétaire dans Guesty.
            ->addColumn('owner_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('property_id', 'char', ['limit' => 36])
            ->addColumn('platform', 'enum', ['values' => ['booking', 'airbnb', 'abritel', 'website', 'manual', 'direct', 'owner']])
            // `source` brut de Guesty (casse d'origine) et clés de son objet `integration`.
            ->addColumn('source', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('integration_keys', 'json')
            ->addColumn('platform_ref', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('guest', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('check_in', 'datetime')
            ->addColumn('check_out', 'datetime')
            ->addColumn('nights', 'integer', ['default' => 0])
            ->addColumn('guests', 'integer', ['default' => 0])
            // Montants bruts Guesty
            ->addColumn('host_payout', 'decimal', $money)
            ->addColumn('host_service_fee', 'decimal', $money)
            ->addColumn('total_payment_fees', 'decimal', $money)
            ->addColumn('total_taxes', 'decimal', $money)
            ->addColumn('fare_cleaning', 'decimal', $money)
            ->addColumn('total_fees', 'decimal', $money)
            ->addColumn('transient_occupancy_tax', 'decimal', $money)
            ->addColumn('fare_accommodation_adjusted', 'decimal', $money)
            ->addColumn('payments', 'json')
            ->addColumn('confirmation_taxes', 'json')
            // Montants calculés
            ->addColumn('total_payout', 'decimal', $money)
            ->addColumn('channel_commission', 'decimal', $money)
            ->addColumn('payment_fee', 'decimal', $money)
            ->addColumn('tourist_tax', 'decimal', $money)
            ->addColumn('cleaning_fee', 'decimal', $money)
            ->addColumn('net_payout', 'decimal', $money)
            ->addColumn('concierge_fee', 'decimal', $money)
            ->addColumn('net_owner_revenue', 'decimal', $money)
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'guesty_id'], ['unique' => true, 'name' => 'uq_reservations_tenant_guesty'])
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_reservations_tenant_id'])
            ->addIndex(['tenant_id', 'check_out'], ['name' => 'idx_reservations_tenant_check_out'])
            ->addIndex(['tenant_id', 'owner_id', 'check_out'], ['name' => 'idx_reservations_tenant_owner_check_out'])
            ->addIndex(['tenant_id', 'property_id'], ['name' => 'idx_reservations_tenant_property'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();

        $this->table('reservation_flags', ['id' => false, 'primary_key' => ['reservation_id', 'field']])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('reservation_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('field', 'enum', ['null' => false, 'values' => ['total_payout', 'channel_commission', 'payment_fee', 'tourist_tax', 'cleaning_fee', 'net_payout', 'concierge_fee', 'net_owner_revenue']])
            // Code de raison (snake_case), traduit par le front.
            ->addColumn('reason', 'string', ['limit' => 190])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'reservation_id'], ['name' => 'idx_reservation_flags_tenant_reservation'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->addForeignKey(['tenant_id', 'reservation_id'], 'reservations', ['tenant_id', 'id'], ['delete' => 'CASCADE', 'update' => 'RESTRICT'])
            ->create();
    }
}
