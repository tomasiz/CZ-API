<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Logements (listings) importés de Guesty. Les tarifs ménage et linge sont locaux à Casamiza : le tarif ménage de
 * Guesty n'initialise la valeur qu'à la création, puis n'est plus jamais écrasé par une synchronisation.
 */
final class CreateProperties extends AbstractMigration
{
    public function change(): void
    {
        $this->table('properties', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('guesty_id', 'string', ['limit' => 64])
            // Nul si Guesty ne désigne aucun propriétaire (ou un propriétaire inconnu).
            ->addColumn('owner_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('address', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('cleaning_fee', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('linen_fee', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'guesty_id'], ['unique' => true, 'name' => 'uq_properties_tenant_guesty'])
            ->addIndex(['tenant_id', 'owner_id'], ['name' => 'idx_properties_tenant_owner'])
            ->addForeignKey('tenant_id', 'tenants', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            // Clé composite : un logement ne peut jamais référencer le propriétaire d'un autre tenant.
            ->addForeignKey(['tenant_id', 'owner_id'], 'owners', ['tenant_id', 'id'], ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])
            ->create();
    }
}
