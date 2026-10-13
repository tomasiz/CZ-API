<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Ramsey\Uuid\Uuid;

/**
 * Données de développement : un tenant « Casamea » avec l'équipe du front.
 * Mot de passe commun (dev uniquement) : casamiza-dev-2026. Idempotent, refuse de tourner en production.
 */
final class DevSeeder extends AbstractSeed
{
    private const PASSWORD = 'casamiza-dev-2026';

    public function run(): void
    {
        if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
            throw new RuntimeException('DevSeeder ne doit pas tourner en production.');
        }
        $existing = $this->fetchRow("SELECT id FROM tenants WHERE name = 'Casamea · Conciergerie'");
        if (is_array($existing)) {
            $this->seedCompany((string) $existing['id']);

            return;
        }

        $tenantId = Uuid::uuid7()->toString();
        $this->table('tenants')->insert(['id' => $tenantId, 'name' => 'Casamea · Conciergerie'])->saveData();
        $this->seedCompany($tenantId);

        $hash = password_hash(self::PASSWORD, PASSWORD_ARGON2ID);
        $team = [
            ['Jérémy', 'Leandri', 'jeremy@casamea.test', 'admin'],
            ['Thomas', 'Leandri', 'thomas@casamea.test', 'admin'],
            ['Océane', 'Martini', 'oceane@casamea.test', 'manager'],
            ['Mathis', 'Santoni', 'mathis@casamea.test', 'manager'],
        ];
        foreach ($team as [$first, $last, $email, $role]) {
            $this->table('users')->insert([
                'id' => Uuid::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password_hash' => $hash,
                'role' => $role,
            ])->saveData();
        }
    }

    /** Identité légale de Casamea (mentions publiques des factures) ; ne fait rien si le tenant a déjà ses réglages. */
    private function seedCompany(string $tenantId): void
    {
        if ($this->fetchRow("SELECT id FROM company_settings WHERE tenant_id = '" . $tenantId . "'") !== false) {
            return;
        }

        $this->table('company_settings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'legal_name' => 'SARL Conciergerie CASAMEA',
            'legal_form' => 'SARL',
            'capital' => '1500',
            'legal_address' => '1 rue de l\'Exemple, 20000 Ajaccio',
            'legal_email' => 'legal@casamea.test',
            'legal_phone' => '06 12 34 56 78',
            'siret' => '12345678900012',
            'vat_number' => 'FR12345678901',
            'iban' => 'FR7630006000011234567890189',
            'signatory_first_name' => 'Jérémy',
            'signatory_last_name' => 'Exemple',
            'default_commission' => 20,
            'contact_name' => 'Conciergerie Casamea',
            'contact_email' => 'contact@casamea.test',
            'email_recap_template' => "Bonjour {first_name},\n\nVeuillez trouver ci joint le récapitulatif des locations du mois de {doc_month} {doc_year}.\nVotre revenu net propriétaire sur cette période s'élève à {doc_total} €.\n\nVous souhaitant bonne réception.\n\nBien cordialement,\nJérémy\nConciergerie Casamea",
            'email_invoice_template' => "Bonjour {first_name},\n\nVeuillez trouver ci joint la facture du mois de {doc_month} {doc_year} d'un montant total de {doc_total} € TTC.\nVous trouverez également le récapitulatif complet de vos locations.\n\nVous souhaitant bonne réception.\n\nBien cordialement,\nJérémy\nConciergerie Casamea",
            'logo_path' => $this->seedLogo($tenantId),
        ])->saveData();
    }

    /** Copie le logo de l'ancien outil (s'il est présent à côté du projet) dans `storage/logos/` ; null sinon. */
    private function seedLogo(string $tenantId): ?string
    {
        $source = dirname(__DIR__, 2) . '/Facturiza/back/PDF/images/casamea_logo.png';
        $directory = dirname(__DIR__) . '/storage/logos';
        if (!is_file($source) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            return null;
        }
        $path = 'logos/' . $tenantId . '-seed.png';

        return copy($source, dirname(__DIR__) . '/storage/' . $path) ? $path : null;
    }
}
