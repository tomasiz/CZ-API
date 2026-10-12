<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Configuration des services tiers (Guesty, Mailsend, Pennylane) : une ligne par (tenant, service).
 * Les secrets et le jeton d'accès mis en cache sont chiffrés (libsodium) ; ils ne sont jamais renvoyés par l'API.
 */
final class CreateIntegrations extends AbstractMigration
{
    public function change(): void
    {
        $this->table('integrations', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('provider', 'enum', ['values' => ['guesty', 'mailsend', 'pennylane']])
            // JSON chiffré des secrets (clé API, client secret…), encodé en base64.
            ->addColumn('credentials_encrypted', 'text')
            // Réglages non secrets, lisibles par le front (ex. identifiant de modèle Mailsend).
            ->addColumn('settings', 'json', ['null' => true])
            // Jeton d'accès du tiers mis en cache (Guesty : 24 h, 5 renouvellements par jour au maximum).
            ->addColumn('token_encrypted', 'text', ['null' => true])
            ->addColumn('token_expires_at', 'datetime', ['null' => true])
            // Résultat du dernier contrôle ; « non configuré » = pas de ligne.
            ->addColumn('status', 'enum', ['values' => ['connected', 'error']])
            ->addColumn('last_error_code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('last_checked_at', 'datetime', ['null' => true])
            ->addColumn('configured_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'provider'], ['unique' => true, 'name' => 'uq_integrations_tenant_provider'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }
}
