<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Journal d'audit : ajout seul (jamais de UPDATE), purgé après 13 mois par `audit:purge`.
 * Pas de clé étrangère vers `users` : une ligne survit à la suppression de l'acteur ou de la cible, d'où `actor_label`.
 */
final class CreateAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('audit_log', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('actor_user_id', 'char', ['limit' => 36, 'null' => true])
            // Nom de l'acteur au moment des faits (« cli » pour une commande, nul pour une tentative anonyme).
            ->addColumn('actor_label', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 50])
            ->addColumn('target_type', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('target_id', 'string', ['limit' => 36, 'null' => true])
            // Libellé lisible de la cible au moment des faits (numéro de facture, nom du propriétaire ou du membre).
            ->addColumn('target_label', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('request_id', 'char', ['limit' => 16, 'null' => true])
            // Identifiants et codes seulement : jamais de secret, de jeton ni de donnée voyageur.
            ->addColumn('context', 'json', ['null' => true])
            ->addIndex(['tenant_id', 'occurred_at'], ['name' => 'idx_audit_log_tenant_date'])
            ->addIndex(['tenant_id', 'action', 'occurred_at'], ['name' => 'idx_audit_log_tenant_action'])
            ->addIndex(['tenant_id', 'actor_user_id', 'occurred_at'], ['name' => 'idx_audit_log_tenant_actor'])
            ->addIndex(['occurred_at'], ['name' => 'idx_audit_log_date'])
            ->create();
    }
}
