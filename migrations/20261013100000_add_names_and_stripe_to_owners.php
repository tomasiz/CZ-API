<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prénom et nom séparés (Guesty les fournit ; le nom de fichier et l'e-mail des documents utilisent le nom de famille et
 * le prénom) et clé Stripe du propriétaire. `name` reste le nom complet affiché dans les listes.
 *
 * La clé Stripe est chiffrée au repos (libsodium) et n'est jamais renvoyée par l'API : elle sert à lire les frais de
 * paiement des réservations (voir CLAUDE.md §10.5).
 */
final class AddNamesAndStripeToOwners extends AbstractMigration
{
    public function up(): void
    {
        $this->table('owners')
            ->addColumn('first_name', 'string', ['limit' => 190, 'default' => '', 'after' => 'name'])
            ->addColumn('last_name', 'string', ['limit' => 190, 'default' => '', 'after' => 'first_name'])
            ->addColumn('stripe_secret_encrypted', 'text', ['null' => true, 'after' => 'commission'])
            ->update();

        // Reprise provisoire : dernier mot = nom de famille. La prochaine synchronisation Guesty écrit les vraies valeurs.
        $this->execute(
            "UPDATE owners SET
                last_name = SUBSTRING_INDEX(name, ' ', -1),
                first_name = TRIM(SUBSTRING(name, 1, CHAR_LENGTH(name) - CHAR_LENGTH(SUBSTRING_INDEX(name, ' ', -1))))",
        );
    }

    public function down(): void
    {
        $this->table('owners')
            ->removeColumn('first_name')
            ->removeColumn('last_name')
            ->removeColumn('stripe_secret_encrypted')
            ->update();
    }
}
