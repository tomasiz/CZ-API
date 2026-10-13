<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\Transaction;
use Throwable;

/**
 * Reprend le SIRET, la raison sociale et (sur demande) la clé Stripe saisis dans Facturiza (`owners.legal_siret`,
 * `owners.legal_name`, `owners.stripe_api_key`) pour les propriétaires du tenant courant, reliés par leur identifiant Guesty.
 *
 * Règles : seules les valeurs absentes de Casamiza sont écrites (une saisie locale n'est **jamais** écrasée, un
 * désaccord est signalé) ; un identifiant qui n'a ni 14 chiffres (SIRET) ni 9 (SIREN, repris tel quel : c'est ce que
 * Facturiza imprimait) n'est pas repris ; relancer l'import ne change rien de plus. En simulation (`$apply = false`),
 * rien n'est écrit.
 *
 * Clé Stripe : reprise seulement avec `$withStripeKeys`, chiffrée avant d'être écrite ; une clé n'apparaît **jamais**
 * dans le bilan (ni en clair, ni tronquée), seulement son type (`sk_live`, `rk_live`…).
 */
final readonly class FacturizaOwnerImporter
{
    private const STRIPE_KEY = '/^((?:sk|rk)_(?:live|test))_\w{10,}$/';

    public function __construct(
        private OwnerRepositoryInterface $owners,
        private Transaction $transaction,
        private Encryptor $encryptor,
    ) {}

    /** @param list<array<string, string|int|float|null>> $legacyOwners lignes de la table `owners` de Facturiza */
    public function import(array $legacyOwners, bool $apply, bool $withStripeKeys = false, bool $withIdentity = true): OwnerImportReport
    {
        $byGuestyId = [];
        foreach ($this->owners->all() as $owner) {
            $byGuestyId[$owner->guestyId] = $owner;
        }

        return $this->transaction->run(function () use ($legacyOwners, $byGuestyId, $apply, $withStripeKeys, $withIdentity): OwnerImportReport {
            $withData = 0;
            $updated = $unchanged = $conflicts = $invalid = $notFound = [];

            foreach ($legacyOwners as $legacy) {
                // Sans l'identité (`$withIdentity = false`), SIRET et raison sociale ne sont pas lus : une valeur effacée
                // exprès dans Casamiza ne revient pas.
                $siret = $withIdentity ? self::digits((string) ($legacy['legal_siret'] ?? '')) : '';
                $company = $withIdentity ? self::text((string) ($legacy['legal_name'] ?? '')) : '';
                $stripe = $withStripeKeys ? trim((string) ($legacy['stripe_api_key'] ?? '')) : '';
                if ($siret === '' && $company === '' && $stripe === '') {
                    continue;
                }
                $withData++;

                $owner = $byGuestyId[(string) ($legacy['guesty_owner_id'] ?? '')] ?? null;
                if ($owner === null) {
                    $notFound[] = [self::label($legacy), 'absent de Casamiza'];
                    continue;
                }

                $newSiret = null;
                if ($siret !== '' && preg_match('/^(\d{9}|\d{14})$/', $siret) !== 1) {
                    $invalid[] = [$owner->name, 'SIRET « ' . $siret . ' » (' . strlen($siret) . ' chiffres)'];
                } else {
                    $newSiret = $siret;
                }
                $newStripe = null;
                if ($stripe !== '' && preg_match(self::STRIPE_KEY, $stripe) !== 1) {
                    $invalid[] = [$owner->name, 'clé Stripe de format inattendu (' . strlen($stripe) . ' caractères)'];
                } elseif ($stripe !== '') {
                    $newStripe = $stripe;
                }

                $changes = [];
                $newCompany = $company === '' ? null : $company;
                foreach ([['SIRET', $owner->siret, $newSiret], ['raison sociale', $owner->companyName, $newCompany]] as [$field, $current, $incoming]) {
                    if ($incoming === null || $incoming === '') {
                        continue;
                    }
                    if ($current === '') {
                        $changes[] = $field . ' = ' . $incoming;
                    } elseif ($current === $incoming) {
                        $unchanged[] = [$owner->name, $field . ' déjà à jour'];
                    } else {
                        $conflicts[] = [$owner->name, $field . ' : Casamiza « ' . $current . ' » conservé, Facturiza « ' . $incoming . ' »'];
                    }
                }

                $encrypted = null;
                if ($newStripe !== null) {
                    $kind = self::stripeKind($newStripe);
                    if (!$owner->hasStripeKey()) {
                        $changes[] = 'clé Stripe ' . $kind;
                        $encrypted = $this->encryptor->encrypt($newStripe);
                    } elseif ($this->sameKey($owner, $newStripe)) {
                        $unchanged[] = [$owner->name, 'clé Stripe déjà à jour'];
                    } else {
                        $conflicts[] = [$owner->name, 'clé Stripe : celle de Casamiza est conservée, Facturiza en a une autre (' . $kind . ')'];
                    }
                }

                if ($changes !== []) {
                    $updated[] = [$owner->name, implode(', ', $changes)];
                    if ($apply) {
                        $this->owners->updateLocal($this->withValues($owner, $newSiret, $newCompany, $encrypted));
                    }
                }
            }

            return new OwnerImportReport($withData, $updated, $unchanged, $conflicts, $invalid, $notFound, $apply);
        });
    }

    /** Applique uniquement les valeurs que Casamiza n'a pas déjà : jamais d'écrasement. */
    private function withValues(Owner $owner, ?string $siret, ?string $company, ?string $encryptedStripeKey): Owner
    {
        $updated = $owner->withLocalFields(
            $owner->companyName === '' ? $company : null,
            $owner->siret === '' && $siret !== '' ? $siret : null,
        );

        return $encryptedStripeKey === null ? $updated : $updated->withStripeSecret($encryptedStripeKey);
    }

    private function sameKey(Owner $owner, string $plain): bool
    {
        try {
            return hash_equals($this->encryptor->decrypt((string) $owner->stripeSecretEncrypted), $plain);
        } catch (Throwable) {
            return false;
        }
    }

    /** Type de clé (`sk_live`, `rk_live`…), seule partie d'une clé qui peut s'afficher. */
    private static function stripeKind(string $key): string
    {
        return preg_match(self::STRIPE_KEY, $key, $m) === 1 ? $m[1] : 'inconnue';
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/[\s.]+/', '', $value);
    }

    private static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param array<string, string|int|float|null> $legacy */
    private static function label(array $legacy): string
    {
        return self::text(((string) ($legacy['first_name'] ?? '')) . ' ' . ((string) ($legacy['last_name'] ?? ''))) . ' (' . (string) ($legacy['guesty_owner_id'] ?? '') . ')';
    }
}
