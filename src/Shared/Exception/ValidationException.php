<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class ValidationException extends ApiException
{
    /** @param array<string, list<string>> $errors codes d'erreur par champ (`required`, `invalid_email`…) */
    public function __construct(array $errors, string $code = 'validation_failed')
    {
        parent::__construct($code, 422, $errors);
    }
}
