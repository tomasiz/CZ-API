<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Propriétaires importés de Guesty. `guesty_id` identifie la ligne côté Guesty (unique par tenant) ; `siret` et
 * `company_name` sont locaux à Casamiza et ne sont jamais écrasés par une synchronisation.
 * `archived_at` : propriétaire disparu ou désactivé dans Guesty (jamais supprimé, l'historique de facturation en dépend).
 */
final class CreateOwners extends AbstractMigration
{
    public function change(): void
    {
        $this->table('owners', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('guesty_id', 'string', ['limit' => 64])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('email', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('phone', 'string', ['limit' => 50, 'default' => ''])
            ->addColumn('address', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('type', 'enum', ['values' => ['individual', 'company'], 'default' => 'individual'])
            ->addColumn('company_name', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('siret', 'string', ['limit' => 20, 'default' => ''])
            // Pourcentage de commission de la conciergerie, lu dans Guesty (businessInformation.ownerCommission).
            ->addColumn('commission', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'guesty_id'], ['unique' => true, 'name' => 'uq_owners_tenant_guesty'])
            // Cible des clés étrangères composites (tenant_id, owner_id) des tables qui référencent un propriétaire.
            ->addIndex(['tenant_id', 'id'], ['unique' => true, 'name' => 'uq_owners_tenant_id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }
}
