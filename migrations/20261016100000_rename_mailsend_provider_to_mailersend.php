<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le service d'e-mail s'appelle MailerSend : l'identifiant `mailsend` des intégrations devient `mailersend`. Les lignes
 * existantes sont reprises (la valeur doit exister dans l'énumération pendant le changement).
 */
final class RenameMailsendProviderToMailersend extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE integrations MODIFY provider ENUM('guesty', 'mailsend', 'mailersend', 'pennylane') NOT NULL");
        $this->execute("UPDATE integrations SET provider = 'mailersend' WHERE provider = 'mailsend'");
        $this->execute("ALTER TABLE integrations MODIFY provider ENUM('guesty', 'mailersend', 'pennylane') NOT NULL");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE integrations MODIFY provider ENUM('guesty', 'mailsend', 'mailersend', 'pennylane') NOT NULL");
        $this->execute("UPDATE integrations SET provider = 'mailsend' WHERE provider = 'mailersend'");
        $this->execute("ALTER TABLE integrations MODIFY provider ENUM('guesty', 'mailsend', 'pennylane') NOT NULL");
    }
}
