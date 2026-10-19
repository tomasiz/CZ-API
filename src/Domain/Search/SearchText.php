<?php

declare(strict_types=1);

namespace Casamiza\Domain\Search;

/** Comparaison de texte de la recherche globale : sans accent ni casse, chaque mot cherché doit se retrouver. */
final class SearchText
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ì' => 'i',
        'ñ' => 'n',
        'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Minuscules et sans accent. */
    public static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), self::ACCENTS);
    }

    /** @return list<string> mots de la recherche, normalisés */
    public static function terms(string $query): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', self::normalize(trim($query))) ?: [],
            static fn(string $term): bool => $term !== '',
        ));
    }

    /**
     * @param list<string> $terms mots normalisés (`terms()`)
     * @param list<string> $fields éléments dans lesquels chercher
     */
    public static function matches(array $terms, array $fields): bool
    {
        $haystack = self::normalize(implode(' ', $fields));
        foreach ($terms as $term) {
            if (!str_contains($haystack, $term)) {
                return false;
            }
        }

        return $terms !== [];
    }
}
