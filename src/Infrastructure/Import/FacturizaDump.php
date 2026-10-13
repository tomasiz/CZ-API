<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Import;

use InvalidArgumentException;

/**
 * Lecture d'un export SQL (phpMyAdmin / mysqldump) de Facturiza : extrait les lignes des `INSERT INTO` d'une table,
 * sans jamais exécuter le fichier. Les valeurs sont des chaînes, des nombres ou null.
 */
final class FacturizaDump
{
    private const ESCAPES = ['n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a", 'b' => "\x08"];

    /**
     * @return list<array<string, string|int|float|null>> une ligne par tuple, indexée par nom de colonne
     */
    public static function rows(string $sql, string $table): array
    {
        $header = '/INSERT INTO `' . preg_quote($table, '/') . '` \(([^)]*)\) VALUES\s*/';
        if (preg_match_all($header, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $rows = [];
        foreach ($matches as $match) {
            $columns = array_map(static fn(string $c): string => trim($c, "` \n"), explode(',', $match[1][0]));
            $position = $match[0][1] + strlen($match[0][0]);
            self::readTuples($sql, $position, $columns, $rows);
        }

        return $rows;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, string|int|float|null>> $rows
     */
    private static function readTuples(string $sql, int $i, array $columns, array &$rows): void
    {
        $length = strlen($sql);
        while ($i < $length) {
            if ($sql[$i] !== '(') {
                throw new InvalidArgumentException('Export SQL illisible à l\'octet ' . $i . '.');
            }
            $i++;
            $values = [];
            while (true) {
                while ($i < $length && $sql[$i] === ' ') {
                    $i++;
                }
                if ($i >= $length) {
                    throw new InvalidArgumentException('Export SQL tronqué.');
                }
                $values[] = $sql[$i] === "'" ? self::readString($sql, $i) : self::readScalar($sql, $i);
                $separator = $sql[$i] ?? '';
                $i++;
                if ($separator === ')') {
                    break;
                }
                if ($separator !== ',') {
                    throw new InvalidArgumentException('Export SQL illisible à l\'octet ' . $i . '.');
                }
            }
            if (count($values) !== count($columns)) {
                throw new InvalidArgumentException('Nombre de valeurs inattendu dans l\'export SQL.');
            }
            $rows[] = array_combine($columns, $values);

            $next = $sql[$i] ?? ';';
            if ($next === ';') {
                return;
            }
            $i += 2; // « ,\n » entre deux tuples
        }
    }

    /** Lit une chaîne SQL entre apostrophes ; `$i` finit juste après l'apostrophe fermante. */
    private static function readString(string $sql, int &$i): string
    {
        $i++;
        $out = '';
        while (true) {
            $stop = strcspn($sql, "'\\", $i);
            $out .= substr($sql, $i, $stop);
            $i += $stop;
            $char = $sql[$i] ?? throw new InvalidArgumentException('Export SQL tronqué.');
            if ($char === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    $out .= "'";
                    $i += 2;
                    continue;
                }
                $i++;

                return $out;
            }
            $escaped = $sql[$i + 1] ?? '';
            $out .= self::ESCAPES[$escaped] ?? $escaped;
            $i += 2;
        }
    }

    /** Lit NULL ou un nombre ; `$i` finit sur le séparateur. */
    private static function readScalar(string $sql, int &$i): int|float|null
    {
        $stop = strcspn($sql, ',)', $i);
        $token = trim(substr($sql, $i, $stop));
        $i += $stop;
        if ($token === 'NULL') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $token) === 1) {
            return (int) $token;
        }
        if (is_numeric($token)) {
            return (float) $token;
        }

        throw new InvalidArgumentException('Valeur SQL inattendue : ' . $token);
    }
}
