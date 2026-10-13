<?php

declare(strict_types=1);

namespace Casamiza\Http\Request;

use Casamiza\Shared\Exception\ValidationException;

/**
 * Lecture validée du corps JSON d'une requête. Les erreurs sont des codes par champ
 * (`required`, `invalid_type`, `too_long`, `invalid_email`) levés ensemble par assertValid().
 * Les champs non lus sont ignorés ; appeler les accesseurs pour chaque champ attendu.
 */
final class Input
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<array-key, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromBody(mixed $parsedBody): self
    {
        return new self(is_array($parsedBody) ? $parsedBody : []);
    }

    public function string(string $field, int $max = 255, bool $trim = true): string
    {
        $value = $this->read($field, true, $max, $trim);

        return $value ?? '';
    }

    public function optionalString(string $field, int $max = 255, bool $trim = true): ?string
    {
        return $this->read($field, false, $max, $trim);
    }

    /** Le champ est-il présent dans le corps (même vide ou null) ? Distingue « inchangé » de « à effacer ». */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /** Texte facultatif qui peut être vidé : `''` si absent, vide ou null. */
    public function text(string $field, int $max = 255): string
    {
        return $this->read($field, false, $max, true) ?? '';
    }

    /** Nombre facultatif entre `$min` et `$max` (nombre JSON ou chaîne numérique, virgule acceptée) ; null si absent ou vide. */
    public function optionalNumber(string $field, float $min, float $max): ?float
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $this->invalid($field, 'invalid_type');
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            return $this->invalid($field, 'out_of_range');
        }

        return $number;
    }

    /** Booléen JSON obligatoire (`true` / `false`). */
    public function boolean(string $field): bool
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            $this->errors[$field][] = 'required';

            return false;
        }
        if (!is_bool($value)) {
            $this->errors[$field][] = 'invalid_type';

            return false;
        }

        return $value;
    }

    /** Montant en euros (au plus 2 décimales, virgule acceptée, négatif accepté : un avoir) ; null si absent (obligatoire si `$required`). */
    public function money(string $field, bool $required, float $max = 100000): ?float
    {
        if (($this->data[$field] ?? null) === null || ($this->data[$field] ?? null) === '') {
            if ($required) {
                $this->errors[$field][] = 'required';
            }

            return null;
        }
        $number = $this->optionalNumber($field, -$max, $max);
        $raw = $this->data[$field] ?? null;
        $text = is_string($raw) ? str_replace(',', '.', trim($raw)) : (string) $raw;
        if ($number !== null && preg_match('/^-?\d+(\.\d{1,2})?$/', $text) !== 1) {
            return $this->invalid($field, 'invalid_format');
        }

        return $number;
    }

    /** Enregistre une erreur de format sur un champ (à lever avec assertValid()). */
    public function fail(string $field, string $code = 'invalid_format'): void
    {
        $this->errors[$field][] = $code;
    }

    /** Rejette tout champ hors liste (anti mass-assignment) : `unknown_field`. */
    public function allowOnly(string ...$fields): void
    {
        foreach (array_keys($this->data) as $key) {
            if (!in_array((string) $key, $fields, true)) {
                $this->errors[(string) $key] = ['unknown_field'];
            }
        }
    }

    public function email(string $field): string
    {
        $value = $this->string($field, 190);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field][] = 'invalid_email';
        }

        return $value;
    }

    /**
     * Valeur d'une énumération (`admin`, `manager`…) ; null si absente (invalide si `required`).
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function optionalEnum(string $field, string $enum): ?\BackedEnum
    {
        $value = $this->read($field, false, 50, true);
        if ($value === null) {
            return null;
        }

        return $enum::tryFrom($value) ?? $this->invalid($field, 'invalid_value');
    }

    /**
     * Valeur obligatoire d'une énumération.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $field, string $enum): ?\BackedEnum
    {
        $value = $this->read($field, true, 50, true);
        if ($value === null) {
            return null;
        }

        return $enum::tryFrom($value) ?? $this->invalid($field, 'invalid_value');
    }

    /**
     * Liste de valeurs séparées par des virgules (`a,b,c`), vide si absente. Au plus `$maxItems` valeurs de `$maxLength`
     * caractères ; les doublons sont ignorés.
     *
     * @return list<string>
     */
    public function stringList(string $field, int $maxItems = 100, int $maxLength = 64): array
    {
        $value = $this->read($field, false, $maxItems * ($maxLength + 1), true);
        if ($value === null) {
            return [];
        }
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== '')));
        if (count($items) > $maxItems) {
            return $this->invalidList($field, 'too_many');
        }
        foreach ($items as $item) {
            if (mb_strlen($item) > $maxLength) {
                return $this->invalidList($field, 'too_long');
            }
        }

        return $items;
    }

    /** Entier entre `$min` et `$max` (chaîne numérique acceptée) ; `$default` si absent. */
    public function integer(string $field, int $min, int $max, int $default): int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value)) {
            $this->errors[$field][] = 'invalid_type';

            return $default;
        }
        if ($value < $min || $value > $max) {
            $this->errors[$field][] = 'out_of_range';

            return $default;
        }

        return $value;
    }

    public function assertValid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }

    /** @return list<string> */
    private function invalidList(string $field, string $code): array
    {
        $this->errors[$field][] = $code;

        return [];
    }

    private function invalid(string $field, string $code): null
    {
        $this->errors[$field][] = $code;

        return null;
    }

    private function read(string $field, bool $required, int $max, bool $trim): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = 'required';
            }

            return null;
        }
        if (!is_string($value)) {
            $this->errors[$field][] = 'invalid_type';

            return null;
        }
        $value = $trim ? trim($value) : $value;
        if ($required && $value === '') {
            $this->errors[$field][] = 'required';

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$field][] = 'too_long';

            return null;
        }

        return $value;
    }
}
