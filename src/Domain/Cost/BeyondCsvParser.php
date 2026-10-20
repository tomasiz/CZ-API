<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use Casamiza\Shared\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lit le CSV « par réservation » exporté de Beyond (`transactions-AAAA-MM-JJ_AAAA-MM-JJ.csv`). Un fichier est accepté en entier
 * ou refusé en entier : au moindre défaut (colonne manquante, montant illisible, devise autre que l'euro, ligne en double,
 * `Amount` différent de `Beyond Fees`…), rien n'est lu et la réponse liste les premières lignes en cause (`line_N`).
 *
 * Les colonnes se retrouvent par leur nom, dans n'importe quel ordre ; les colonnes inconnues sont ignorées.
 * La commission Beyond « pro_package » vaut 1 % des gains, **tronqué** au centime : un écart donne un avertissement.
 */
final readonly class BeyondCsvParser
{
    public const MAX_ROWS = 20000;
    private const MAX_REPORTED_ERRORS = 10;
    private const REQUIRED = ['BP StatementItem ID', 'PMS Reservation ID', 'Booking Date', 'Description', 'Listing Title', 'Check-In Date', 'Nights', 'Currency', 'Earnings', 'Beyond Fees', 'Amount', 'Product'];

    /** @throws ValidationException `file` (`invalid_encoding`, `invalid_header`, `empty`, `too_many_rows`) ou `line_N` (code de la première erreur de la ligne) */
    public function parse(string $csv): BeyondCsvResult
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw new ValidationException(['file' => ['invalid_encoding']]);
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Impossible d’ouvrir un flux mémoire.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');
        $columns = is_array($header) ? array_flip(array_map(static fn(?string $name): string => trim((string) $name), $header)) : [];
        foreach (self::REQUIRED as $name) {
            if (!isset($columns[$name])) {
                fclose($stream);

                throw new ValidationException(['file' => ['invalid_header']]);
            }
        }

        $lines = [];
        $warnings = [];
        $errors = [];
        $seen = [];
        $nonBillable = 0;
        $row = 1;
        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $row++;
            if ($cells === [null]) {
                continue;
            }
            if (count($lines) >= self::MAX_ROWS) {
                fclose($stream);

                throw new ValidationException(['file' => ['too_many_rows']]);
            }
            $get = static fn(string $name): string => trim((string) ($cells[$columns[$name]] ?? ''));

            // Un ajustement « Non-Billable » (gains et frais à zéro) ne porte aucune commission.
            if ($get('Description') === 'Non-Billable') {
                $nonBillable++;
                continue;
            }

            $line = $this->line($row, $get, isset($columns['Updated Date']) ? $get('Updated Date') : '');
            if (is_string($line) || isset($seen[$line->statementItemId])) {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors["line_{$row}"] = [is_string($line) ? $line : 'duplicate_item'];
                }
                continue;
            }
            $seen[$line->statementItemId] = true;
            $lines[] = $line;

            $warning = $this->warning($line);
            if ($warning !== null) {
                $warnings[] = ['row' => $row, 'code' => $warning];
            }
        }
        fclose($stream);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($lines === [] && $nonBillable === 0) {
            throw new ValidationException(['file' => ['empty']]);
        }

        return new BeyondCsvResult($lines, $warnings, $nonBillable);
    }

    /**
     * @param callable(string): string $get
     * @return BeyondFeeLine|string la ligne, ou le code de sa première erreur
     */
    private function line(int $row, callable $get, string $updatedOn): BeyondFeeLine|string
    {
        $id = $get('BP StatementItem ID');
        if (preg_match('/^\d{1,15}$/', $id) !== 1) {
            return 'invalid_id';
        }
        $pms = $get('PMS Reservation ID');
        if ($pms === '' || mb_strlen($pms) > 64) {
            return 'invalid_reservation_id';
        }
        $title = $get('Listing Title');
        if ($title === '' || mb_strlen($title) > 190) {
            return 'invalid_listing';
        }
        $kind = match ($get('Description')) {
            'Reservation' => BeyondFeeKind::Reservation,
            'Cancellation' => BeyondFeeKind::Cancellation,
            'Update' => BeyondFeeKind::Update,
            default => null,
        };
        if ($kind === null) {
            return 'invalid_kind';
        }
        $booking = self::date($get('Booking Date'), 'Y-m-d');
        $updated = $updatedOn === '' ? null : self::date($updatedOn, 'Y-m-d');
        $checkIn = self::date($get('Check-In Date'), 'M d, Y');
        if ($booking === null || $checkIn === null || ($updatedOn !== '' && $updated === null)) {
            return 'invalid_date';
        }
        $nights = $get('Nights');
        if (preg_match('/^\d{1,4}$/', $nights) !== 1) {
            return 'invalid_nights';
        }
        if ($get('Currency') !== 'EUR') {
            return 'unsupported_currency';
        }
        $earnings = self::cents($get('Earnings'));
        $fees = self::cents($get('Beyond Fees'));
        $amount = self::cents($get('Amount'));
        if ($earnings === null || $fees === null || $amount === null) {
            return 'invalid_amount';
        }
        if ($fees !== $amount) {
            return 'amount_mismatch';
        }

        return new BeyondFeeLine($row, (int) $id, $pms, $title, $kind, $get('Product'), $booking, $updated, $checkIn, (int) $nights, $earnings, $amount);
    }

    private function warning(BeyondFeeLine $line): ?string
    {
        if ($line->product !== 'pro_package') {
            return 'unknown_product';
        }
        $expected = ($line->earningsCents < 0 ? -1 : 1) * intdiv(abs($line->earningsCents), 100);

        return $line->amountCents === $expected ? null : 'fee_not_one_percent';
    }

    /** « 12.34 » ou « -0.5 » en centimes ; null si ce n'est pas un montant à deux décimales au plus. */
    private static function cents(string $value): ?int
    {
        if (preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** Jour au format donné (relecture stricte : « 2026-02-30 » est refusé), à minuit UTC. */
    private static function date(string $value, string $format): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format($format) === $value ? $date : null;
    }
}
