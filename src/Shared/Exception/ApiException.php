<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

use RuntimeException;

/**
 * Erreur « métier » convertie en réponse `{error: {code, fields?}}`.
 * Elle porte un **code** `snake_case` (jamais une phrase) que le front traduit.
 */
abstract class ApiException extends RuntimeException
{
    /** @param array<string, list<string>> $errors codes d'erreur par champ */
    public function __construct(
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $errors = [],
    ) {
        parent::__construct($errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
