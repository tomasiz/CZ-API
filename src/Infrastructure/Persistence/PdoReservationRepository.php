<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Reservation\FinancialField;
use Casamiza\Domain\Reservation\FlagReason;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationFlag;
use Casamiza\Domain\Reservation\ReservationPage;
use Casamiza\Domain\Reservation\ReservationQuery;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationRow;
use Casamiza\Domain\Reservation\ReservationSort;
use Casamiza\Domain\Reservation\ReservationTotals;
use DateTimeImmutable;
use UnexpectedValueException;

final class PdoReservationRepository extends BaseRepository implements ReservationRepositoryInterface
{
    protected function table(): string
    {
        return 'reservations';
    }

    public function checkingOutBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->select(
            where: 'archived_at IS NULL AND check_out >= :from AND check_out < :to',
            params: ['from' => DbDate::toDb($from), 'to' => DbDate::toDb($to)],
            suffix: 'ORDER BY check_out, id',
        );
        $flags = $this->flagsCheckingOut($from, $to);

        return array_map(fn(array $row): Reservation => $this->hydrate($row, $flags[(string) $row['id']] ?? []), $rows);
    }

    public function allCheckingOutSince(DateTimeImmutable $from, ?DateTimeImmutable $before = null): array
    {
        $params = ['from' => DbDate::toDb($from)];
        if ($before !== null) {
            $params['before'] = DbDate::toDb($before);
        }
        $rows = $this->select(
            where: 'check_out >= :from' . ($before === null ? '' : ' AND check_out < :before'),
            params: $params,
            suffix: 'ORDER BY check_out, id',
        );
        $flags = $this->flagsCheckingOut($from, $before);

        return array_map(fn(array $row): Reservation => $this->hydrate($row, $flags[(string) $row['id']] ?? []), $rows);
    }

    /**
     * Recherche en MySQL (CONCAT_WS, DATE_FORMAT) : non couverte par les tests SQLite, vérifiée sur la base de dev.
     * Tout est borné au tenant courant, y compris les jointures ; les valeurs passent par des paramètres liés, et le tri
     * par une liste blanche.
     */
    public function search(ReservationQuery $query): ReservationPage
    {
        $params = ['tenant' => $this->tenant->id()];
        $conditions = $this->searchConditions($query, $params);
        $from = ' FROM reservations r'
            . ' JOIN properties p ON p.tenant_id = r.tenant_id AND p.id = r.property_id'
            . ' LEFT JOIN owners o ON o.tenant_id = r.tenant_id AND o.id = r.owner_id'
            . ' WHERE r.tenant_id = :tenant AND r.archived_at IS NULL' . $conditions;

        $statement = $this->pdo()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(r.total_payout), 0) AS total_payout, COALESCE(SUM(r.concierge_fee), 0) AS concierge_fee,'
            . ' COALESCE(SUM(r.net_owner_revenue), 0) AS net_owner_revenue' . $from);
        $statement->execute($params);
        $sums = $statement->fetch();
        if (!is_array($sums)) {
            $sums = ['n' => 0];
        }
        $totals = new ReservationTotals((float) ($sums['total_payout'] ?? 0), (float) ($sums['concierge_fee'] ?? 0), (float) ($sums['net_owner_revenue'] ?? 0));

        $order = match ($query->sort) {
            ReservationSort::CheckIn => 'r.check_in',
            ReservationSort::CheckOut => 'r.check_out',
            ReservationSort::Owner => 'o.name',
            ReservationSort::Property => 'p.name',
            ReservationSort::Guest => 'r.guest',
            ReservationSort::TotalPaid => 'r.total_payout',
            ReservationSort::ConciergeFee => 'r.concierge_fee',
            ReservationSort::OwnerRevenue => 'r.net_owner_revenue',
        };
        $direction = $query->descending ? 'DESC' : 'ASC';
        $statement = $this->pdo()->prepare(
            "SELECT r.*, p.name AS property_name, COALESCE(o.name, '') AS owner_name{$from} ORDER BY {$order} {$direction}, r.id LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $query->perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', ($query->page - 1) * $query->perPage, \PDO::PARAM_INT);
        $statement->execute();
        $rows = array_values($statement->fetchAll());

        $flags = $this->flagsOfRows($rows);

        return new ReservationPage(
            array_map(
                fn(array $row): ReservationRow => new ReservationRow($this->hydrate($row, $flags[(string) $row['id']] ?? []), (string) $row['owner_name'], (string) $row['property_name']),
                $rows,
            ),
            (int) ($sums['n'] ?? 0),
            $totals,
            $query->page,
            $query->perPage,
        );
    }

    /**
     * Conditions SQL (commençant par ` AND`) de la recherche ; les paramètres liés s'ajoutent à `$params`.
     *
     * @param array<string, mixed> $params
     */
    private function searchConditions(ReservationQuery $query, array &$params): string
    {
        $sql = '';
        if ($query->checkOutFrom !== null) {
            $sql .= ' AND r.check_out >= :from';
            $params['from'] = DbDate::toDb($query->checkOutFrom);
        }
        if ($query->checkOutBefore !== null) {
            $sql .= ' AND r.check_out < :before';
            $params['before'] = DbDate::toDb($query->checkOutBefore);
        }
        $sql .= $this->inList('r.owner_id', 'owner', $query->ownerIds, $params);
        $sql .= $this->inList('r.property_id', 'property', $query->propertyIds, $params);
        $sql .= $this->inList('r.platform', 'platform', array_map(static fn($p): string => $p->value, $query->platforms), $params);
        if ($query->flagged !== null) {
            $sql .= ($query->flagged ? ' AND EXISTS' : ' AND NOT EXISTS')
                . ' (SELECT 1 FROM reservation_flags f WHERE f.tenant_id = r.tenant_id AND f.reservation_id = r.id)';
        }
        foreach ($query->searchTerms() as $i => $term) {
            // Un montant tapé à la française (« 602,5 ») se cherche tel qu'il est stocké (« 602.50 »).
            if (preg_match('/^-?\d+,\d+$/', $term) === 1) {
                $term = str_replace(',', '.', $term);
            }
            $sql .= " AND CONCAT_WS(' ', r.guest, r.platform_ref, r.guesty_id, r.source, r.platform, p.name, p.address, o.name, o.company_name,"
                . " DATE_FORMAT(r.check_in, '%d/%m/%Y'), DATE_FORMAT(r.check_out, '%d/%m/%Y'), DATE(r.check_in), DATE(r.check_out), r.nights, r.guests,"
                . ' r.total_payout, r.channel_commission, r.payment_fee, r.tourist_tax, r.cleaning_fee, r.net_payout, r.concierge_fee, r.net_owner_revenue)'
                . " LIKE :term{$i}";
            $params["term{$i}"] = '%' . addcslashes($term, '\\%_') . '%';
        }

        return $sql;
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $params
     */
    private function inList(string $column, string $prefix, array $values, array &$params): string
    {
        if ($values === []) {
            return '';
        }
        $names = [];
        foreach ($values as $i => $value) {
            $names[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $value;
        }

        return " AND {$column} IN (" . implode(', ', $names) . ')';
    }

    /**
     * Signalements des réservations données.
     *
     * @param list<array<array-key, mixed>> $rows
     * @return array<string, list<ReservationFlag>> par identifiant de réservation
     */
    private function flagsOfRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $params = ['tenant' => $this->tenant->id()];
        $in = $this->inList('f.reservation_id', 'id', array_map(static fn(array $row): string => (string) $row['id'], $rows), $params);
        $statement = $this->pdo()->prepare('SELECT f.reservation_id, f.field, f.reason FROM reservation_flags f WHERE f.tenant_id = :tenant' . $in . ' ORDER BY f.field');
        $statement->execute($params);

        $flags = [];
        foreach ($statement->fetchAll() as $row) {
            $flags[(string) $row['reservation_id']][] = $this->hydrateFlag($row);
        }

        return $flags;
    }

    public function findByGuestyId(string $guestyId): ?Reservation
    {
        $row = $this->selectOne('guesty_id = :guesty_id', ['guesty_id' => $guestyId]);
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->flagsOf((string) $row['id']));
    }

    public function add(Reservation $reservation): void
    {
        $this->atomically(function () use ($reservation): void {
            $this->insertRow(['id' => $reservation->id, 'guesty_id' => $reservation->guestyId] + $this->values($reservation));
            $this->writeFlags($reservation);
        });
    }

    public function update(Reservation $reservation): void
    {
        $this->atomically(function () use ($reservation): void {
            // Une réservation d'un autre tenant n'est pas touchée, et ses signalements non plus.
            if ($this->updateRow($reservation->id, $this->values($reservation)) === 0 && $this->findRow($reservation->id) === null) {
                return;
            }
            $this->writeFlags($reservation);
        });
    }

    public function reassignProperty(string $propertyId, ?string $ownerId): int
    {
        // Seules les réservations qui changent de propriétaire sont écrites (et comptées).
        $changed = $ownerId === null ? 'owner_id IS NOT NULL' : '(owner_id IS NULL OR owner_id <> :owner_check)';
        $statement = $this->pdo()->prepare(
            "UPDATE reservations SET owner_id = :owner_id WHERE tenant_id = :__tenant AND property_id = :property_id AND {$changed}",
        );
        $params = ['owner_id' => $ownerId, 'property_id' => $propertyId, '__tenant' => $this->tenant->id()];
        $statement->execute($ownerId === null ? $params : $params + ['owner_check' => $ownerId]);

        return $statement->rowCount();
    }

    /** @return array<string, mixed> */
    private function values(Reservation $r): array
    {
        return [
            'owner_id' => $r->ownerId,
            'property_id' => $r->propertyId,
            'platform' => $r->platform->value,
            'source' => $r->source,
            'integration_keys' => json_encode($r->integrationKeys, JSON_THROW_ON_ERROR),
            'platform_ref' => $r->platformRef,
            'guest' => $r->guest,
            'check_in' => DbDate::toDb($r->checkIn),
            'check_out' => DbDate::toDb($r->checkOut),
            'nights' => $r->nights,
            'guests' => $r->guests,
            'host_payout' => $r->hostPayout,
            'host_service_fee' => $r->hostServiceFee,
            'total_payment_fees' => $r->totalPaymentFees,
            'total_taxes' => $r->totalTaxes,
            'fare_cleaning' => $r->fareCleaning,
            'total_fees' => $r->totalFees,
            'transient_occupancy_tax' => $r->transientOccupancyTax,
            'fare_accommodation_adjusted' => $r->fareAccommodationAdjusted,
            'payments' => json_encode($r->payments, JSON_THROW_ON_ERROR),
            'confirmation_taxes' => json_encode($r->confirmationTaxes, JSON_THROW_ON_ERROR),
            'total_payout' => $r->totalPayout,
            'channel_commission' => $r->channelCommission,
            'payment_fee' => $r->paymentFee,
            'tourist_tax' => $r->touristTax,
            'cleaning_fee' => $r->cleaningFee,
            'net_payout' => $r->netPayout,
            'concierge_fee' => $r->conciergeFee,
            'net_owner_revenue' => $r->netOwnerRevenue,
            'archived_at' => $r->archivedAt === null ? null : DbDate::toDb($r->archivedAt),
            'stripe_fee' => $r->stripeFee,
            'stripe_error' => $r->stripeError,
        ];
    }

    /** Remplace les signalements de la réservation (toujours bornés au tenant courant). */
    private function writeFlags(Reservation $reservation): void
    {
        $tenant = $this->tenant->id();
        $this->pdo()
            ->prepare('DELETE FROM reservation_flags WHERE tenant_id = :tenant AND reservation_id = :reservation')
            ->execute(['tenant' => $tenant, 'reservation' => $reservation->id]);

        $insert = $this->pdo()->prepare('INSERT INTO reservation_flags (tenant_id, reservation_id, field, reason) VALUES (:tenant, :reservation, :field, :reason)');
        foreach ($reservation->flags as $flag) {
            $insert->execute(['tenant' => $tenant, 'reservation' => $reservation->id, 'field' => $flag->field->value, 'reason' => $flag->reason->value]);
        }
    }

    /** @return list<ReservationFlag> */
    private function flagsOf(string $reservationId): array
    {
        $statement = $this->pdo()->prepare('SELECT field, reason FROM reservation_flags WHERE tenant_id = :tenant AND reservation_id = :reservation ORDER BY field');
        $statement->execute(['tenant' => $this->tenant->id(), 'reservation' => $reservationId]);

        return array_map($this->hydrateFlag(...), array_values($statement->fetchAll()));
    }

    /**
     * Signalements des réservations dont le check-out est dans `[$from, $to[` (sans borne haute si `$to` est nul).
     *
     * @return array<string, list<ReservationFlag>> par identifiant de réservation
     */
    private function flagsCheckingOut(DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $params = ['tenant' => $this->tenant->id(), 'from' => DbDate::toDb($from)];
        if ($to !== null) {
            $params['to'] = DbDate::toDb($to);
        }
        $statement = $this->pdo()->prepare(
            'SELECT f.reservation_id, f.field, f.reason FROM reservation_flags f'
            . ' JOIN reservations r ON r.tenant_id = f.tenant_id AND r.id = f.reservation_id'
            . ' WHERE f.tenant_id = :tenant AND r.check_out >= :from' . ($to === null ? '' : ' AND r.check_out < :to') . ' ORDER BY f.field',
        );
        $statement->execute($params);

        $flags = [];
        foreach ($statement->fetchAll() as $row) {
            $flags[(string) $row['reservation_id']][] = $this->hydrateFlag($row);
        }

        return $flags;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrateFlag(array $row): ReservationFlag
    {
        return new ReservationFlag(FinancialField::from((string) $row['field']), FlagReason::from((string) $row['reason']));
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<ReservationFlag> $flags
     */
    private function hydrate(array $row, array $flags): Reservation
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Réservation sans identifiant.');
        }
        $keys = self::json($row['integration_keys']);

        return new Reservation(
            id: $id,
            guestyId: (string) $row['guesty_id'],
            ownerId: $row['owner_id'] === null ? null : (string) $row['owner_id'],
            propertyId: (string) $row['property_id'],
            platform: Platform::from((string) $row['platform']),
            source: (string) $row['source'],
            integrationKeys: array_values(array_map(strval(...), $keys)),
            platformRef: (string) $row['platform_ref'],
            guest: (string) $row['guest'],
            checkIn: DbDate::fromDb($row['check_in']),
            checkOut: DbDate::fromDb($row['check_out']),
            nights: (int) $row['nights'],
            guests: (int) $row['guests'],
            hostPayout: (float) $row['host_payout'],
            hostServiceFee: (float) $row['host_service_fee'],
            totalPaymentFees: (float) $row['total_payment_fees'],
            totalTaxes: (float) $row['total_taxes'],
            fareCleaning: (float) $row['fare_cleaning'],
            totalFees: (float) $row['total_fees'],
            transientOccupancyTax: (float) $row['transient_occupancy_tax'],
            fareAccommodationAdjusted: (float) $row['fare_accommodation_adjusted'],
            payments: self::json($row['payments']),
            confirmationTaxes: self::json($row['confirmation_taxes']),
            totalPayout: (float) $row['total_payout'],
            channelCommission: (float) $row['channel_commission'],
            paymentFee: (float) $row['payment_fee'],
            touristTax: (float) $row['tourist_tax'],
            cleaningFee: (float) $row['cleaning_fee'],
            netPayout: (float) $row['net_payout'],
            conciergeFee: (float) $row['concierge_fee'],
            netOwnerRevenue: (float) $row['net_owner_revenue'],
            archivedAt: DbDate::fromDbOrNull($row['archived_at']),
            flags: $flags,
            stripeFee: $row['stripe_fee'] === null ? null : (float) $row['stripe_fee'],
            stripeError: $row['stripe_error'] === null ? null : (string) $row['stripe_error'],
        );
    }

    /** @return array<array-key, mixed> */
    private static function json(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Exécute dans une transaction, sauf si l'appelant en a déjà ouvert une. */
    private function atomically(callable $work): void
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            $work();

            return;
        }
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
