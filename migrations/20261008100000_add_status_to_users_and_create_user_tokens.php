<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Un membre invité existe (status = invited) sans mot de passe tant qu'il n'a pas activé son compte ;
 * `user_tokens` porte les jetons à usage unique (activation maintenant, réinitialisation de mot de passe ensuite).
 */
final class AddStatusToUsersAndCreateUserTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->changeColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['invited', 'active'], 'default' => 'active', 'after' => 'role'])
            ->update();

        $this->table('user_tokens', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tenant_id', 'char', ['limit' => 36])
            ->addColumn('user_id', 'char', ['limit' => 36])
            ->addColumn('purpose', 'enum', ['values' => ['activation', 'password_reset']])
            // SHA-256 hexadécimal : le jeton lui-même (envoyé par e-mail) n'est jamais stocké.
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_user_tokens_hash'])
            ->addIndex(['tenant_id', 'user_id'], ['name' => 'idx_user_tokens_tenant_user'])
            ->addForeignKey(['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], ['delete' => 'CASCADE', 'update' => 'RESTRICT'])
            ->create();
    }

    public function down(): void
    {
        $this->table('user_tokens')->drop()->save();
        $this->execute("DELETE FROM users WHERE status = 'invited'");
        $this->execute("UPDATE users SET password_hash = '' WHERE password_hash IS NULL");
        $this->table('users')
            ->removeColumn('status')
            ->changeColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->update();
    }
}
