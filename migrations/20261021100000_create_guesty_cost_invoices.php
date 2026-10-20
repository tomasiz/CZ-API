<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Factures de Guesty (export xlsx de la page Facturation, montants en dollars). `guesty_cost_invoices` en garde une par numéro
 * (avec le fichier rangé hors webroot, le cours USD→EUR de la BCE fixé à l'import) et `guesty_cost_items` une ligne par annonce
 * facturée : montant en dollars et en euros, mois du coût (mois du début de la période facturée) et logement trouvé par le
 * nom de l'annonce (nul si aucun logement ne le porte). Une ligne vue deux fois (même annonce, même début, deux factures) n'est
 * comptée qu'une fois (`counted`). `guesty_listing_aliases` rattache à un logement le nom d'une annonce renommée depuis.
 */
final class CreateGuestyCostInvoices extends AbstractMigration
{
    public function change(): void
    {
        $restrict = ['delete' => 'RESTRICT', 'update' => 'RESTRICT'];

        $this->table('guesty_cost_invoices', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            // « INV00814774 », lu dans le nom du fichier.
            ->addColumn('number', 'string', ['limit' => 32])
            ->addColumn('source_file', 'string', ['limit' => 190])
            ->addColumn('file_path', 'string', ['limit' => 255])
            ->addColumn('file_sha256', 'char', ['limit' => 64])
            // Absente du fichier : le 1er du mois facturé d'avance, ou le lendemain de la fin pour une facture de prorata seul.
            ->addColumn('invoice_date', 'date')
            // Dollars → euros, cours de référence de la BCE à la date de la facture (jour ouvré précédent si besoin).
            ->addColumn('fx_rate', 'decimal', ['precision' => 10, 'scale' => 6])
            ->addColumn('lines_count', 'integer')
            ->addColumn('amount_usd', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('processed_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'number'], ['unique' => true, 'name' => 'uq_guesty_cost_invoices_tenant_number'])
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_guesty_cost_invoices_tenant_id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->create();

        $this->table('guesty_cost_items', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('invoice_id', 'char', ['limit' => 36])
            ->addColumn('property_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('listing_nickname', 'string', ['limit' => 190])
            // Nom de l'annonce sans casse ni espaces superflus : sert à repérer une ligne vue deux fois.
            ->addColumn('listing_key', 'string', ['limit' => 190])
            ->addColumn('item', 'string', ['limit' => 40])
            ->addColumn('kind', 'enum', ['values' => ['monthly', 'prorata']])
            ->addColumn('period_start', 'date')
            ->addColumn('period_end', 'date')
            // AAAA-MM du début de la période : le mois où le logement a coûté.
            ->addColumn('cost_month', 'char', ['limit' => 7])
            ->addColumn('amount_usd', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2])
            // Faux pour une ligne déjà portée par une autre facture : elle ne compte pas deux fois.
            ->addColumn('counted', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'invoice_id'], ['name' => 'ix_guesty_cost_items_invoice'])
            ->addIndex(['tenant_id', 'listing_key', 'period_start'], ['name' => 'ix_guesty_cost_items_listing'])
            ->addIndex(['tenant_id', 'cost_month'], ['name' => 'ix_guesty_cost_items_month'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'invoice_id'], 'guesty_cost_invoices', ['tenant_id', 'id'], $restrict)
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], $restrict)
            ->create();

        $this->table('guesty_listing_aliases', ['id' => false, 'primary_key' => ['tenant_id', 'alias_key']])
            ->addColumn('tenant_id', 'char', ['limit' => 36, 'null' => false])
            // Nom de l'annonce réduit à ses lettres et chiffres, en minuscules.
            ->addColumn('alias_key', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('property_id', 'char', ['limit' => 36])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('tenant_id', 'tenants', 'id', $restrict)
            ->addForeignKey(['tenant_id', 'property_id'], 'properties', ['tenant_id', 'id'], $restrict)
            ->create();
    }
}
