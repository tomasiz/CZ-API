<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Statement\Statement;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use UnexpectedValueException;

final class PdoStatementRepository extends BaseRepository implements StatementRepositoryInterface
{
    protected function table(): string
    {
        return 'monthly_statements';
    }

    public function where(?string $ownerId, ?string $month): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($ownerId !== null) {
            $where[] = 'owner_id = :owner';
            $params['owner'] = $ownerId;
        }
        if ($month !== null) {
            $where[] = 'month = :month';
            $params['month'] = $month;
        }

        return array_map($this->hydrate(...), $this->select(where: implode(' AND ', $where), params: $params));
    }

    public function find(string $ownerId, string $month): ?Statement
    {
        $row = $this->selectOne('owner_id = :owner AND month = :month', ['owner' => $ownerId, 'month' => $month]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(Statement $statement): void
    {
        $values = ['nothing_to_invoice' => $statement->nothingToInvoice ? 1 : 0];
        if ($this->find($statement->ownerId, $statement->month) === null) {
            $this->insertRow(['id' => $statement->id, 'owner_id' => $statement->ownerId, 'month' => $statement->month] + $values);

            return;
        }
        $row = $this->selectOne('owner_id = :owner AND month = :month', ['owner' => $statement->ownerId, 'month' => $statement->month], 'id');
        $this->updateRow((string) ($row['id'] ?? ''), $values);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Statement
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Mention mensuelle sans identifiant.');
        }

        return new Statement($id, (string) $row['owner_id'], (string) $row['month'], (bool) $row['nothing_to_invoice']);
    }
}
