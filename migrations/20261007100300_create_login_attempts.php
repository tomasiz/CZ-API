<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Journal anti-force-brute, avant authentification : volontairement sans tenant_id, e-mail stocké haché. */
final class CreateLoginAttempts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('login_attempts', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('email_hash', 'char', ['limit' => 64])
            ->addColumn('ip', 'string', ['limit' => 45])
            ->addColumn('success', 'boolean')
            ->addColumn('attempted_at', 'datetime')
            ->addIndex(['email_hash', 'ip', 'attempted_at'], ['name' => 'idx_login_attempts_email_ip'])
            ->addIndex(['ip', 'attempted_at'], ['name' => 'idx_login_attempts_ip'])
            ->create();
    }
}
