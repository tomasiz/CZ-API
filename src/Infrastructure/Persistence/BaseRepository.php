<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Shared\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * Socle de tous les repositories métier : **toute** requête est bornée au tenant courant.
 *
 * Les fragments `$where` et `$suffix` sont écrits par le développeur (jamais issus d'une entrée
 * utilisateur) ; les valeurs passent toujours par des paramètres liés. Les noms de colonnes des
 * insertions / mises à jour sont validés. Un repository n'écrit pas de SQL métier hors de cette classe.
 */
abstract class BaseRepository
{
    public function __construct(
        protected readonly Database $database,
        protected readonly TenantContext $tenant,
    ) {}

    /** Nom de la table gérée (constante du repository, pas une entrée utilisateur). */
    abstract protected function table(): string;

    /**
     * @param array<string, mixed> $params
     * @return list<array<array-key, mixed>>
     */
    protected function select(string $columns = '*', string $where = '1 = 1', array $params = [], string $suffix = ''): array
    {
        $sql = "SELECT {$columns} FROM {$this->table()} WHERE tenant_id = :__tenant AND ({$where}) {$suffix}";
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params + ['__tenant' => $this->tenant->id()]);

        return array_values($statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>|null
     */
    protected function selectOne(string $where, array $params, string $columns = '*'): ?array
    {
        return $this->select($columns, $where, $params, 'LIMIT 1')[0] ?? null;
    }

    /** @return array<array-key, mixed>|null */
    protected function findRow(string $id): ?array
    {
        return $this->selectOne('id = :__id', ['__id' => $id]);
    }

    /** @param array<string, mixed> $row */
    protected function insertRow(array $row): void
    {
        $row['tenant_id'] = $this->tenant->id();
        $columns = $this->columns(array_keys($row));
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->table(), implode(', ', $columns), $placeholders);
        $this->database->pdo()->prepare($sql)->execute($row);
    }

    /** @param array<string, mixed> $values @return int lignes modifiées */
    protected function updateRow(string $id, array $values): int
    {
        unset($values['tenant_id'], $values['id']);
        if ($values === []) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", $this->columns(array_keys($values))));

        $sql = sprintf('UPDATE %s SET %s WHERE tenant_id = :__tenant AND id = :__id', $this->table(), $sets);
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($values + ['__tenant' => $this->tenant->id(), '__id' => $id]);

        return $statement->rowCount();
    }

    protected function deleteRow(string $id): int
    {
        $sql = sprintf('DELETE FROM %s WHERE tenant_id = :__tenant AND id = :__id', $this->table());
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute(['__tenant' => $this->tenant->id(), '__id' => $id]);

        return $statement->rowCount();
    }

    protected function pdo(): PDO
    {
        return $this->database->pdo();
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function columns(array $names): array
    {
        foreach ($names as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Nom de colonne invalide : {$name}");
            }
        }

        return $names;
    }
}
