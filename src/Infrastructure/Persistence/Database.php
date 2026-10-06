<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Shared\Settings;
use PDO;

/** Connexion PDO paresseuse : elle n'est ouverte que si une requête en a besoin. */
final class Database
{
    private ?PDO $pdo;

    public function __construct(private readonly Settings $settings, ?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    private function connect(): PDO
    {
        $s = $this->settings;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s->dbHost, $s->dbPort, $s->dbName);

        return new PDO($dsn, $s->dbUser, $s->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Vraies requêtes préparées côté serveur : pas d'injection par échappement.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            // Dates stockées en UTC ; mode strict.
            \Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00', "
                . "sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE'",
        ]);
    }
}
