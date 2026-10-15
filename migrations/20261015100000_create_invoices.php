<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Factures (étape D) : `invoices` (en-tête, totaux, paiement, instantané JSON), `invoice_lines` (le facturé, une ligne
 * par composante), `invoice_reservations` (l'activité figée de chaque réservation facturée, pour le tableau de bord) et
 * `tenant_invoice_sequences` (prochain numéro, par tenant).
 *
 * Tout est figé à l'émission : aucune de ces lignes n'est recalculée. Montants en `DECIMAL(12,2)` ; le HT des lignes est
 * réparti pour retomber exactement sur le HT du total (voir FACTURIZA.md §11.D).
 *
 * Une seule facture non annulée par (propriétaire, mois) : colonne générée `active_slot` (1 tant que la facture n'est
 * pas annulée, NULL ensuite) dans un index unique, les NULL ne comptant pas.
 */
final class CreateInvoices extends AbstractMigration
{
    public function up(): void
    {
        $money = ['precision' => 12, 'scale' => 2];
        $cascade = ['delete' => 'CASCADE', 'update' => 'RESTRICT'];
        $restrict = ['delete' => 'RESTRICT', 'update' => 'RESTRICT'];

        $this->table('tenant_invoice_sequences', ['id' => false, 'primary_key' => 'tenant_id'])
            ->addColumn('tenant_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('next_number', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->create();

        $this->table('invoices', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('number', 'integer', ['signed' => false])
            ->addColumn('owner_id', 'char', ['limit' => 36])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('period_start', 'date')
            ->addColumn('period_end', 'date')
            ->addColumn('invoice_date', 'date')
            ->addColumn('due_date', 'date')
            ->addColumn('amount_ht', 'decimal', $money)
            ->addColumn('vat', 'decimal', $money)
            ->addColumn('amount_ttc', 'decimal', $money)
            ->addColumn('payment_status', 'enum', ['values' => ['unpaid', 'paid'], 'default' => 'unpaid'])
            ->addColumn('payment_method', 'enum', ['values' => ['wire', 'cash', 'credit_card', 'other'], 'null' => true])
            ->addColumn('payment_date', 'date', ['null' => true])
            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addColumn('note', 'text', ['null' => true])
            // Instantané légal (structure de FACTURIZA.md §5.6) ; NULL pour les factures 1 à 6 de Facturiza.
            ->addColumn('snapshot', 'json', ['null' => true])
            // Chemins relatifs à la racine de stockage, nom aléatoire.
            ->addColumn('pdf_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('recap_pdf_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('pdf_sha256', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('source', 'enum', ['values' => ['casamiza', 'facturiza_import'], 'default' => 'casamiza'])
            ->addColumn('legacy_id', 'integer', ['signed' => false, 'null' => true])
            // Lien Pennylane : jamais renseigné pour les factures importées.
            ->addColumn('pennylane_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('pennylane_synced_at', 'datetime', ['null' => true])
            ->addColumn('pennylane_error', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_invoices_tenant_id'])
            ->addIndex(['tenant_id', 'number'], ['unique' => true, 'name' => 'uq_invoices_tenant_number'])
            ->addIndex(['tenant_id', 'legacy_id'], ['unique' => true, 'name' => 'uq_invoices_tenant_legacy'])
            ->addIndex(['tenant_id', 'pennylane_id'], ['unique' => true, 'name' => 'uq_invoices_tenant_pennylane'])
            ->addIndex(['tenant_id', 'month'], ['name' => 'idx_invoices_tenant_month'])
            ->addIndex(['tenant_id', 'owner_id', 'month'], ['name' => 'idx_invoices_tenant_owner_month'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], $restrict)
            ->create();

        $this->execute('ALTER TABLE invoices ADD COLUMN active_slot TINYINT GENERATED ALWAYS AS (IF(cancelled_at IS NULL, 1, NULL)) STORED');
        $this->execute('ALTER TABLE invoices ADD UNIQUE INDEX uq_invoices_active_owner_month (tenant_id, owner_id, month, active_slot)');

        // Clé composite nécessaire à la clé étrangère de `invoice_lines.extra_id`.
        $this->table('extras')
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_extras_tenant_id'])
            ->update();

        $this->table('invoice_lines', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('invoice_id', 'char', ['limit' => 36])
            ->addColumn('position', 'integer', ['signed' => false])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('kind', 'enum', ['values' => ['reservation', 'extra']])
            ->addColumn('category', 'enum', ['values' => ['concierge', 'linen', 'cleaning']])
            // Nul pour une prestation (rattachée au propriétaire) ; renseigné pour une réservation.
            ->addColumn('property_id', 'char', ['limit' => 36, 'null' => true])
            // Nuls si la réservation n'existe plus dans Casamiza (facture importée) ou si la prestation est supprimée.
            ->addColumn('reservation_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('extra_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('label', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('price_ttc', 'decimal', $money)
            ->addColumn('vat_rate', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => 20])
            ->addColumn('price_ht', 'decimal', $money)
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'invoice_id', 'position'], ['unique' => true, 'name' => 'uq_invoice_lines_position'])
            ->addIndex(['tenant_id', 'property_id', 'month'], ['name' => 'idx_invoice_lines_tenant_property_month'])
            ->addIndex(['tenant_id', 'month', 'category'], ['name' => 'idx_invoice_lines_tenant_month_category'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'invoice_id'], 'invoices', ['tenant_id', 'id'], $cascade)
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'reservation_id'], 'reservations', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'extra_id'], 'extras', ['tenant_id', 'id'], $restrict)
            ->create();

        $this->table('invoice_reservations', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('invoice_id', 'char', ['limit' => 36])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('owner_id', 'char', ['limit' => 36])
            ->addColumn('property_id', 'char', ['limit' => 36])
            ->addColumn('reservation_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('platform', 'enum', ['values' => ['booking', 'airbnb', 'abritel', 'website', 'manual', 'direct', 'owner']])
            ->addColumn('check_in', 'datetime')
            ->addColumn('check_out', 'datetime')
            ->addColumn('nights', 'integer', ['default' => 0])
            ->addColumn('total_payout', 'decimal', $money + ['default' => 0])
            ->addColumn('channel_commission', 'decimal', $money + ['default' => 0])
            ->addColumn('payment_fee', 'decimal', $money + ['default' => 0])
            ->addColumn('tourist_tax', 'decimal', $money + ['default' => 0])
            ->addColumn('cleaning_fee', 'decimal', $money + ['default' => 0])
            ->addColumn('net_payout', 'decimal', $money + ['default' => 0])
            ->addColumn('concierge_fee', 'decimal', $money + ['default' => 0])
            ->addColumn('net_owner_revenue', 'decimal', $money + ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'invoice_id'], ['name' => 'idx_invoice_reservations_tenant_invoice'])
            ->addIndex(['tenant_id', 'property_id', 'month'], ['name' => 'idx_invoice_reservations_tenant_property_month'])
            ->addIndex(['tenant_id', 'month'], ['name' => 'idx_invoice_reservations_tenant_month'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'invoice_id'], 'invoices', ['tenant_id', 'id'], $cascade)
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'reservation_id'], 'reservations', ['tenant_id', 'id'], $restrict)
            ->create();
    }

    public function down(): void
    {
        $this->table('invoice_reservations')->drop()->save();
        $this->table('invoice_lines')->drop()->save();
        $this->table('extras')->removeIndexByName('uq_extras_tenant_id')->update();
        $this->table('invoices')->drop()->save();
        $this->table('tenant_invoice_sequences')->drop()->save();
    }
}
