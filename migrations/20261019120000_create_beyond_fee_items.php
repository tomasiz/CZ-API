<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Commissions de Beyond Pricing. Beyond publie un relevé du 12 au 12 (CSV « par réservation ») : `beyond_statements` en garde un
 * par période traitée (avec le CSV rangé hors webroot), `beyond_fee_items` une ligne par ligne du relevé. Un relevé est traité en
 * entier ou pas du tout : chaque ligne a donc sa réservation. Une annulation est une ligne négative.
 * `reservations.beyond_fee` est la somme des lignes d'une réservation : coût interne, lu par le tableau de bord seulement,
 * jamais écrit par la synchronisation Guesty ni montré au propriétaire.
 */
final class CreateBeyondFeeItems extends AbstractMigration
{
    public function change(): void
    {
        $restrict = ['delete' => 'RESTRICT', 'update' => 'RESTRICT'];

        $this->table('beyond_statements', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            // Du 12 (inclus) au 12 suivant (exclu), jours calendaires.
            ->addColumn('period_start', 'date')
            ->addColumn('period_end', 'date')
            ->addColumn('source_file', 'string', ['limit' => 190])
            // Chemin relatif dans storage/ ; le CSV est celui qui a servi au dernier traitement.
            ->addColumn('file_path', 'string', ['limit' => 255])
            ->addColumn('file_sha256', 'char', ['limit' => 64])
            ->addColumn('lines_count', 'integer')
            ->addColumn('reservations_count', 'integer')
            // Somme signée des lignes, en euros.
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('processed_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'period_start'], ['unique' => true, 'name' => 'uq_beyond_statements_tenant_period'])
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_beyond_statements_tenant_id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->create();

        $this->table('beyond_fee_items', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('statement_id', 'char', ['limit' => 36])
            // « BP StatementItem ID » : identifiant de la ligne chez Beyond, qui rend l'import idempotent.
            ->addColumn('statement_item_id', 'biginteger', ['signed' => false])
            // Identifiant Guesty de la réservation, gardé tel que Beyond l'écrit.
            ->addColumn('pms_reservation_id', 'string', ['limit' => 64])
            ->addColumn('reservation_id', 'char', ['limit' => 36])
            ->addColumn('property_id', 'char', ['limit' => 36])
            ->addColumn('listing_title', 'string', ['limit' => 190])
            ->addColumn('kind', 'enum', ['values' => ['reservation', 'cancellation', 'update']])
            ->addColumn('product', 'string', ['limit' => 40])
            ->addColumn('booking_date', 'date')
            ->addColumn('updated_on', 'date', ['null' => true])
            ->addColumn('check_in', 'date')
            ->addColumn('nights', 'integer')
            ->addColumn('currency', 'char', ['limit' => 3])
            // Montants signés (négatifs pour une annulation), en euros.
            ->addColumn('earnings', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('imported_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'statement_item_id'], ['unique' => true, 'name' => 'uq_beyond_fee_items_tenant_item'])
            ->addIndex(['tenant_id', 'statement_id'], ['name' => 'ix_beyond_fee_items_statement'])
            ->addIndex(['tenant_id', 'reservation_id'], ['name' => 'ix_beyond_fee_items_reservation'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'statement_id'], 'beyond_statements', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'reservation_id'], 'reservations', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], $restrict)
            ->create();

        $this->table('reservations')
            ->addColumn('beyond_fee', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'null' => false])
            ->update();
    }
}
