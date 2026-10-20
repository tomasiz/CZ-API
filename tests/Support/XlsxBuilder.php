<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use ZipArchive;

/** Fabrique un export xlsx de facture Guesty (feuilles « Listings » et « Usages ») pour les tests. */
final class XlsxBuilder
{
    /**
     * @param list<array<int, string>> $lines nom, libellé, du, au (AAAA-MM-JJ), montant
     * @param list<string> $header
     */
    public static function build(array $lines, array $header = ['Listing Nickname', 'Item', 'Billing From', 'Billing To', 'Fee']): string
    {
        $rows = [self::row(1, $header)];
        foreach ($lines as $i => $line) {
            [$nickname, $item, $from, $to, $fee] = $line + ['', '', '', '', ''];
            $rows[] = self::row($i + 2, [$nickname, $item, $from === '' ? '' : self::serial($from), $to === '' ? '' : self::serial($to), $fee], [3, 4, 5]);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . implode('', $rows) . '</sheetData></worksheet>';
        $empty = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>';

        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/worksheets/sheet2.xml', $empty);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** Numéro de série Excel d'un jour. */
    public static function serial(string $day): string
    {
        $epoch = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));

        return (string) $epoch->diff(new DateTimeImmutable($day, new DateTimeZone('UTC')))->days;
    }

    /**
     * @param list<string> $values
     * @param list<int> $numeric indices (à partir de 1) des colonnes numériques
     */
    private static function row(int $number, array $values, array $numeric = []): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            if ($value === '') {
                continue;
            }
            $ref = chr(65 + $i) . $number;
            $cells .= in_array($i + 1, $numeric, true)
                ? sprintf('<c r="%s"><v>%s</v></c>', $ref, $value)
                : sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $ref, htmlspecialchars($value, ENT_XML1));
        }

        return sprintf('<row r="%d">%s</row>', $number, $cells);
    }
}
