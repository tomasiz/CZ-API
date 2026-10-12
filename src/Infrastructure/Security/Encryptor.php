<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Chiffrement des secrets au repos (libsodium `secretbox`, XSalsa20-Poly1305). Le résultat est un texte
 * base64 `nonce || chiffré`, stockable dans une colonne TEXT. La clé maître vient de `.env` (APP_ENCRYPTION_KEY).
 */
final readonly class Encryptor
{
    /** @param string $key 32 octets bruts */
    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('La clé de chiffrement doit faire 32 octets.');
        }
    }

    /** Décode la valeur base64 de APP_ENCRYPTION_KEY. */
    public static function fromBase64(string $encodedKey): self
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false) {
            throw new InvalidArgumentException('APP_ENCRYPTION_KEY doit être une clé de 32 octets encodée en base64.');
        }

        return new self($key);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secret chiffré illisible.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new RuntimeException('Secret chiffré illisible (clé incorrecte ou donnée altérée).');
        }

        return $plain;
    }
}
