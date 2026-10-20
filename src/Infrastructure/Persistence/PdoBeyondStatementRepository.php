<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Cost\BeyondPeriod;
use Casamiza\Domain\Cost\BeyondStatement;
use Casamiza\Domain\Cost\BeyondStatementRepositoryInterface;
use DateTimeImmutable;

final class PdoBeyondStatementRepository extends BaseRepository implements BeyondStatementRepositoryInterface
{
    protected function table(): string
    {
        return 'beyond_statements';
    }

    public function find(BeyondPeriod $period): ?BeyondStatement
    {
        $row = $this->selectOne('period_start = :start', ['start' => $period->key()]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function startingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(
            $this->hydrate(...),
            $this->select('*', 'period_start >= :from AND period_start < :to', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')], 'ORDER BY period_start'),
        );
    }

    public function save(BeyondStatement $statement): void
    {
        $values = [
            'source_file' => $statement->sourceFile,
            'file_path' => $statement->filePath,
            'file_sha256' => $statement->fileSha256,
            'lines_count' => $statement->linesCount,
            'reservations_count' => $statement->reservationsCount,
            'amount' => sprintf('%s%d.%02d', $statement->amountCents < 0 ? '-' : '', intdiv(abs($statement->amountCents), 100), abs($statement->amountCents) % 100),
            'processed_at' => DbDate::toDb($statement->processedAt),
        ];
        if ($this->findRow($statement->id) !== null) {
            $this->updateRow($statement->id, $values);

            return;
        }
        $this->insertRow($values + [
            'id' => $statement->id,
            'period_start' => $statement->period->start->format('Y-m-d'),
            'period_end' => $statement->period->end->format('Y-m-d'),
        ]);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): BeyondStatement
    {
        $start = DbDate::fromDb($row['period_start']);

        return new BeyondStatement(
            (string) $row['id'] ?: throw new \LogicException('Relevé Beyond sans identifiant.'),
            BeyondPeriod::startingOn($start) ?? throw new \LogicException('Relevé Beyond qui ne commence pas un 12.'),
            (string) $row['source_file'],
            (string) $row['file_path'],
            (string) $row['file_sha256'],
            (int) $row['lines_count'],
            (int) $row['reservations_count'],
            (int) round(((float) $row['amount']) * 100),
            DbDate::fromDb($row['processed_at']),
        );
    }
}
