<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTenants extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tenants', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('status', 'enum', ['values' => ['active', 'suspended'], 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
