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

    public function assertValid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
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
