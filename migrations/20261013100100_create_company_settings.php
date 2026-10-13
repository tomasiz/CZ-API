<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Identité de la conciergerie (une ligne par tenant) : mentions légales des factures et récapitulatifs, contact des
 * e-mails, modèles d'e-mail et logo. Rien de secret ici (l'IBAN figure déjà au pied de chaque facture).
 *
 * `logo_path` : fichier PNG dans `storage/logos/`, hors webroot. Les valeurs vides signifient « non renseigné ».
 */
final class CreateCompanySettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('company_settings', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('legal_name', 'string', ['limit' => 190, 'default' => ''])
            // « SARL », « SASU »… : imprimé au pied des factures (« SARL au capital de 1500 euros »).
            ->addColumn('legal_form', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('capital', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('legal_address', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('legal_email', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('legal_phone', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('siret', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('vat_number', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('iban', 'string', ['limit' => 40, 'default' => ''])
            // Signataire affiché dans l'en-tête des documents.
            ->addColumn('signatory_first_name', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('signatory_last_name', 'string', ['limit' => 100, 'default' => ''])
            // Commission appliquée quand un propriétaire n'en a pas dans Guesty.
            ->addColumn('default_commission', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('contact_name', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('contact_email', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('email_recap_template', 'text', ['null' => true])
            ->addColumn('email_invoice_template', 'text', ['null' => true])
            ->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'], ['unique' => true, 'name' => 'uq_company_settings_tenant'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }
}
