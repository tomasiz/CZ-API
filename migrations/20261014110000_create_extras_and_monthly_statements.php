<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Suivi mensuel d'un propriétaire : prestations supplémentaires et « pas de facture ce mois-ci ».
 *
 * `extras` : le prix est saisi **TTC** ; le HT se déduit (`price_ttc / (1 + vat_rate / 100)`, non arrondi) et n'est donc
 * pas stocké. La TVA est celle de la prestation (proposée par type dans les réglages de l'entreprise). Suppression
 * logique (`deleted_at`) : une prestation retirée reste en base.
 *
 * `monthly_statements` : une ligne par (propriétaire, mois) dès qu'il y a quelque chose à retenir. Le statut de
 * facturation est calculé (les factures n'existent pas encore) ; seule la bascule « rien à facturer » est stockée.
 */
final class CreateExtrasAndMonthlyStatements extends AbstractMigration
{
    public function change(): void
    {
        $this->table('extras', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('owner_id', 'char', ['limit' => 36])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('type', 'enum', ['values' => ['concierge', 'linen', 'cleaning']])
            ->addColumn('description', 'string', ['limit' => 500])
            ->addColumn('price_ttc', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('vat_rate', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => 20])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'owner_id', 'month'], ['name' => 'idx_extras_tenant_owner_month'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();

        $this->table('monthly_statements', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('owner_id', 'char', ['limit' => 36])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('nothing_to_invoice', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'owner_id', 'month'], ['unique' => true, 'name' => 'uq_statements_tenant_owner_month'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }
}
