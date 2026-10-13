<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\Transaction;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Reprend les propriétaires et les logements de Facturiza que Casamiza ne connaît pas (ils ont disparu de Guesty, mais
 * l'historique de facturation en dépend), avec toutes leurs données : identité, contact, adresse, commission, SIRET,
 * raison sociale, clé Stripe (chiffrée) et, pour les logements, coûts de ménage et de linge.
 *
 * Règles : liaison par identifiant Guesty ; ce que Casamiza a déjà n'est **jamais** modifié ; tout ce qui est créé l'est
 * **archivé** (absent de Guesty = inactif, comme le fait la synchro, qui le laissera tel quel) ; un logement est rattaché
 * à son propriétaire s'il existe dans Casamiza (ou vient d'être créé), sinon il n'a pas de propriétaire. En simulation
 * (`$apply = false`), rien n'est écrit. Relancer l'import ne crée rien de plus.
 */
final readonly class FacturizaDirectoryImporter
{
    private const STRIPE_KEY = '/^(?:sk|rk)_(?:live|test)_\w{10,}$/';

    public function __construct(
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private Transaction $transaction,
        private Encryptor $encryptor,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<array<string, string|int|float|null>> $legacyOwners table `owners` de Facturiza
     * @param list<array<string, string|int|float|null>> $legacyListings table `listings` de Facturiza
     */
    public function import(array $legacyOwners, array $legacyListings, bool $apply): DirectoryImportReport
    {
        $now = $this->clock->now();

        return $this->transaction->run(function () use ($legacyOwners, $legacyListings, $apply, $now): DirectoryImportReport {
            /** @var array<string, string> $ownerIds identifiant Casamiza par identifiant Guesty */
            $ownerIds = [];
            foreach ($this->owners->all() as $owner) {
                $ownerIds[$owner->guestyId] = $owner->id;
            }
            $propertyIds = [];
            foreach ($this->properties->all() as $property) {
                $propertyIds[$property->guestyId] = true;
            }

            $createdOwners = $createdProperties = $warnings = [];
            $ownersPresent = $propertiesPresent = 0;

            foreach ($legacyOwners as $legacy) {
                $guestyId = self::string($legacy['guesty_owner_id'] ?? null);
                if ($guestyId === '') {
                    continue;
                }
                if (isset($ownerIds[$guestyId])) {
                    $ownersPresent++;
                    continue;
                }

                $id = Uuid::uuid7()->toString();
                $owner = $this->owner($id, $guestyId, $legacy, $now, $warnings);
                $ownerIds[$guestyId] = $id;
                $createdOwners[] = [$owner->name, self::ownerDetail($owner)];
                if ($apply) {
                    $this->owners->add($owner);
                }
            }

            foreach ($legacyListings as $legacy) {
                $guestyId = self::string($legacy['guesty_listing_id'] ?? null);
                if ($guestyId === '') {
                    continue;
                }
                if (isset($propertyIds[$guestyId])) {
                    $propertiesPresent++;
                    continue;
                }

                $name = self::text(self::string($legacy['nickname'] ?? null));
                $ownerGuestyId = self::string($legacy['guesty_owner_id'] ?? null);
                $ownerId = $ownerGuestyId === '' ? null : ($ownerIds[$ownerGuestyId] ?? null);
                if ($ownerId === null) {
                    $warnings[] = [$name !== '' ? $name : $guestyId, 'logement sans propriétaire connu dans Casamiza']
                    ;
                }

                $property = new Property(
                    Uuid::uuid7()->toString(),
                    $guestyId,
                    $ownerId,
                    $name !== '' ? $name : $guestyId,
                    self::text(self::string($legacy['address'] ?? null)),
                    self::amount($legacy['cout_menage'] ?? null),
                    self::amount($legacy['cout_linge'] ?? null),
                    $now,
                );
                $propertyIds[$guestyId] = true;
                $createdProperties[] = [$property->name, sprintf('ménage %s €, linge %s €, %s', self::money($property->cleaningFee), self::money($property->linenFee), $ownerId === null ? 'sans propriétaire' : 'propriétaire rattaché')];
                if ($apply) {
                    $this->properties->add($property);
                }
            }

            return new DirectoryImportReport($createdOwners, $createdProperties, $warnings, $ownersPresent, $propertiesPresent, $apply);
        });
    }

    /**
     * @param array<string, string|int|float|null> $legacy
     * @param list<array{string, string}> $warnings
     * @param non-empty-string $id
     */
    private function owner(string $id, string $guestyId, array $legacy, \DateTimeImmutable $now, array &$warnings): Owner
    {
        // Convention du compte Guesty : « P - Prénom Nom ». Le préfixe et les espaces superflus n'ont pas leur place ici.
        $first = self::text((string) preg_replace('/^\s*P\s*-\s*/u', '', self::string($legacy['first_name'] ?? null)));
        $last = self::text(self::string($legacy['last_name'] ?? null));
        $name = self::text($first . ' ' . $last);
        $email = self::text(self::string($legacy['email'] ?? null));
        $company = self::text(self::string($legacy['legal_name'] ?? null));

        $siret = (string) preg_replace('/[\s.]+/', '', self::string($legacy['legal_siret'] ?? null));
        if ($siret !== '' && preg_match('/^(\d{9}|\d{14})$/', $siret) !== 1) {
            $warnings[] = [$name, 'SIRET « ' . $siret . ' » non repris (ni 9 ni 14 chiffres)'];
            $siret = '';
        }

        $stripe = trim(self::string($legacy['stripe_api_key'] ?? null));
        $encrypted = null;
        if ($stripe !== '') {
            if (preg_match(self::STRIPE_KEY, $stripe) === 1) {
                $encrypted = $this->encryptor->encrypt($stripe);
            } else {
                $warnings[] = [$name, 'clé Stripe de format inattendu non reprise'];
            }
        }

        $commission = $legacy['conciergerie_commission'] ?? null;

        return new Owner(
            $id,
            $guestyId,
            $name !== '' ? $name : $email,
            $first,
            $last,
            $email,
            '',
            self::text(self::string($legacy['address'] ?? null)),
            // Facturiza ne connaît pas le type Guesty : « entreprise » dès qu'une raison sociale est renseignée.
            $company !== '' ? OwnerType::Company : OwnerType::Individual,
            $company,
            $siret,
            // -1 = « non renseignée » dans Facturiza.
            (is_int($commission) || is_float($commission)) && $commission >= 0 ? (float) $commission : null,
            $encrypted,
            $now,
        );
    }

    private static function ownerDetail(Owner $owner): string
    {
        return implode(', ', array_filter([
            $owner->type === OwnerType::Company ? 'entreprise' : 'particulier',
            $owner->email !== '' ? $owner->email : null,
            $owner->commission === null ? 'commission non renseignée' : 'commission ' . self::money($owner->commission) . ' %',
            $owner->companyName !== '' ? 'raison sociale ' . $owner->companyName : null,
            $owner->siret !== '' ? 'SIRET/SIREN ' . $owner->siret : null,
            $owner->hasStripeKey() ? 'clé Stripe' : null,
            $owner->address === '' ? 'sans adresse' : null,
        ]));
    }

    private static function string(string|int|float|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function amount(string|int|float|null $value): float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 2)) : 0.0;
    }

    private static function money(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
