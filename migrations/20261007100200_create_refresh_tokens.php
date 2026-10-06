<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRefreshTokens extends AbstractMigration
{
    public function change(): void
    {
        $this->table('refresh_tokens', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('user_id', 'char', ['limit' => 36])
            ->addColumn('family_id', 'char', ['limit' => 36])
            // SHA-256 hexadécimal du jeton : le jeton lui-même n'est jamais stocké.
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('session_expires_at', 'datetime')
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_refresh_tokens_hash'])
            ->addIndex(['family_id'], ['name' => 'idx_refresh_tokens_family'])
            ->addIndex(['tenant_id', 'user_id'], ['name' => 'idx_refresh_tokens_tenant_user'])
            ->addForeignKey(['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], ['delete' => 'CASCADE', 'update' => 'RESTRICT'])
            ->create();
    }
}
