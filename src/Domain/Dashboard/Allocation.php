<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Répartition d'un montant en centimes au prorata de poids, sans perdre un centime. */
final class Allocation
{
    /**
     * Méthode du plus fort reste ; égalités départagées par l'ordre des poids. Des poids tous nuls répartissent à parts
     * égales. Un montant négatif (avoir) se répartit comme son opposé.
     *
     * @param non-empty-list<int> $weights
     * @return list<int> même nombre de parts que de poids, de somme `$total`
     */
    public static function split(int $total, array $weights): array
    {
        $weights = array_map(static fn(int $w): int => max(0, $w), $weights);
        if (array_sum($weights) === 0) {
            $weights = array_fill(0, count($weights), 1);
        }
        $sum = array_sum($weights);
        $sign = $total < 0 ? -1 : 1;
        $amount = abs($total);

        $shares = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $shares[$i] = intdiv($amount * $weight, $sum);
            $remainders[$i] = ($amount * $weight) % $sum;
        }
        $left = $amount - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, static fn(int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_slice($order, 0, $left) as $i) {
            $shares[$i]++;
        }

        return array_map(static fn(int $s): int => $s * $sign, array_values($shares));
    }
}
