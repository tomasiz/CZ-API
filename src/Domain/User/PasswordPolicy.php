<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

use Casamiza\Shared\Exception\ValidationException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    public static function assert(string $password, string $field = 'password'): void
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            throw new ValidationException([$field => ['too_short']]);
        }
        if ($length > self::MAX_LENGTH) {
            throw new ValidationException([$field => ['too_long']]);
        }
    }
}
