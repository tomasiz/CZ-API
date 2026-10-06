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
        if ($this->fetchRow("SELECT id FROM tenants WHERE name = 'Casamea · Conciergerie'") !== false) {
            return;
        }

        $tenantId = Uuid::uuid7()->toString();
        $this->table('tenants')->insert(['id' => $tenantId, 'name' => 'Casamea · Conciergerie'])->saveData();

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
}
