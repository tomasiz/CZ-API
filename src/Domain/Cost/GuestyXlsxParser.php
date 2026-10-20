<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use Casamiza\Shared\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Lit l'export xlsx d'une facture Guesty : feuille « Listings » (colonnes Listing Nickname, Item, Billing From, Billing To, Fee,
 * montants en dollars), la feuille « Usages » étant vide. Le fichier est refusé en entier à la première anomalie : on ne
 * rapporte que dix erreurs. Aucune bibliothèque : un xlsx est un zip de XML, et celui-ci ne contient que des valeurs.
 */
final class GuestyXlsxParser
{
    private const HEADER = ['Listing Nickname', 'Item', 'Billing From', 'Billing To', 'Fee'];
    private const MAX_ROWS = 2000;
    private const MAX_XML_BYTES = 8_000_000;
    private const MAX_REPORTED_ERRORS = 10;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    /** Jour 0 des numéros de série d'Excel (le 1er janvier 1900 est le jour 1, avec le faux 29 février 1900 déjà passé). */
    private const EXCEL_EPOCH = '1899-12-30';

    /** @throws ValidationException le fichier est refusé en entier */
    public function parse(string $bytes): GuestyCostFile
    {
        $cells = $this->cells($bytes);
        $header = $cells[1] ?? [];
        foreach (self::HEADER as $i => $title) {
            if (trim($header[$i] ?? '') !== $title) {
                throw new ValidationException(['file' => ['invalid_header']]);
            }
        }
        unset($cells[1]);
        if (count($cells) > self::MAX_ROWS) {
            throw new ValidationException(['file' => ['too_many_rows']]);
        }

        $lines = [];
        $errors = [];
        foreach ($cells as $row => $values) {
            if (trim(implode('', $values)) === '') {
                continue;
            }
            $line = $this->line($row, $values, $errors);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($lines === []) {
            throw new ValidationException(['file' => ['empty']]);
        }

        return new GuestyCostFile($lines);
    }

    /**
     * @param list<string> $values A à E
     * @param array<string, list<string>> $errors
     */
    private function line(int $row, array $values, array &$errors): ?GuestyCostLine
    {
        $fail = static function (string $code) use ($row, &$errors): null {
            if (count($errors) < self::MAX_REPORTED_ERRORS) {
                $errors["line_{$row}"] = [$code];
            }

            return null;
        };
        $nickname = trim($values[0] ?? '');
        if ($nickname === '' || mb_strlen($nickname) > 190) {
            return $fail('invalid_listing');
        }
        $from = $this->date($values[2] ?? '');
        $to = $this->date($values[3] ?? '');
        if ($from === null || $to === null) {
            return $fail('invalid_date');
        }
        if ($to < $from) {
            return $fail('invalid_period');
        }
        $fee = trim($values[4] ?? '');
        if (!is_numeric($fee) || abs((float) $fee) > 100_000) {
            return $fail('invalid_amount');
        }

        return new GuestyCostLine($row, $nickname, mb_substr(trim($values[1] ?? ''), 0, 40), $from, $to, (int) round((float) $fee * 100));
    }

    /** Un numéro de série Excel, ou un jour `AAAA-MM-JJ`. */
    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $utc = new DateTimeZone('UTC');
        if ($value !== '' && is_numeric($value)) {
            $days = (float) $value;
            if ($days < 1 || $days > 100_000) {
                return null;
            }

            return (new DateTimeImmutable(self::EXCEL_EPOCH, $utc))->modify(sprintf('+%d days', (int) floor($days)));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Les cellules de la première feuille : numéro de ligne => colonnes A à E (vide si absente).
     *
     * @return array<int, list<string>>
     */
    private function cells(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gcost');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new ValidationException(['file' => ['invalid_file']]);
            }
            try {
                $sheet = $this->entry($zip, 'xl/worksheets/sheet1.xml') ?? throw new ValidationException(['file' => ['invalid_file']]);
                $strings = $this->sharedStrings($this->entry($zip, 'xl/sharedStrings.xml'));
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        $xpath = $this->xpath($sheet);
        $rows = [];
        foreach ($xpath->query('//m:sheetData/m:row') ?: [] as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $number = (int) $rowNode->getAttribute('r');
            $values = array_fill(0, count(self::HEADER), '');
            foreach ($xpath->query('m:c', $rowNode) ?: [] as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $column = ord(strtoupper($cell->getAttribute('r'))[0] ?? '?') - 65;
                if ($column < 0 || $column >= count(self::HEADER)) {
                    continue;
                }
                $values[$column] = $this->value($xpath, $cell, $strings);
            }
            $rows[$number] = $values;
        }

        return $rows;
    }

    /** @param list<string> $strings */
    private function value(DOMXPath $xpath, DOMElement $cell, array $strings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            return (string) $xpath->evaluate('string(m:is)', $cell);
        }
        $raw = (string) $xpath->evaluate('string(m:v)', $cell);

        return $type === 's' ? ($strings[(int) $raw] ?? '') : $raw;
    }

    /** @return list<string> */
    private function sharedStrings(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $xpath = $this->xpath($xml);
        $strings = [];
        foreach ($xpath->query('//m:si') ?: [] as $item) {
            if ($item instanceof DOMElement) {
                $strings[] = (string) $xpath->evaluate('string(.)', $item);
            }
        }

        return $strings;
    }

    private function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > self::MAX_XML_BYTES) {
            return null;
        }
        $contents = $zip->getFromName($name);

        return $contents === false ? null : $contents;
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        // Pas d'accès réseau ni d'entité externe : le fichier vient d'un envoi.
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new ValidationException(['file' => ['invalid_file']]);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::NS);

        return $xpath;
    }
}
