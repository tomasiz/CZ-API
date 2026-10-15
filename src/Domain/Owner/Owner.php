<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

use Casamiza\Domain\Guesty\GuestyOwner;
use DateTimeImmutable;

/**
 * Propriétaire. Les champs de Guesty (nom, e-mail, téléphone, adresse, type, commission) sont réécrits à chaque
 * synchronisation ; `companyName`, `siret` et la clé Stripe sont locaux et n'y sont jamais touchés.
 *
 * Deux états distincts : `archivedAt` (hors service : disparu de Guesty, ou inactif **sans** logement actif) et
 * `guestyActive` (valeur brute de Guesty : faux = portail propriétaire non utilisé). Un propriétaire inactif dans Guesty
 * qui a encore un logement actif n'est pas archivé : il reste visible et facturable.
 */
final readonly class Owner
{
    /**
     * @param non-empty-string $id
     * @param ?string $stripeSecretEncrypted clé Stripe chiffrée (libsodium) : jamais en clair, jamais renvoyée par l'API
     */
    public function __construct(
        public string $id,
        public string $guestyId,
        public string $name,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public string $address,
        public OwnerType $type,
        public string $companyName,
        public string $siret,
        public ?float $commission,
        public ?string $stripeSecretEncrypted,
        public ?DateTimeImmutable $archivedAt,
        public bool $guestyActive = true,
    ) {}

    /** @param non-empty-string $id */
    public static function fromGuesty(string $id, GuestyOwner $guesty, DateTimeImmutable $now): self
    {
        return new self($id, $guesty->guestyId, $guesty->name, $guesty->firstName, $guesty->lastName, $guesty->email, $guesty->phone, $guesty->address, $guesty->type, '', '', $guesty->commission, null, $guesty->isDormant() ? $now : null, $guesty->active);
    }

    /** Applique l'état de Guesty ; un propriétaire déjà archivé garde sa date d'archivage tant qu'il reste hors service. */
    public function mergedWith(GuestyOwner $guesty, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->guestyId,
            $guesty->name,
            $guesty->firstName,
            $guesty->lastName,
            $guesty->email,
            $guesty->phone,
            $guesty->address,
            $guesty->type,
            $this->companyName,
            $this->siret,
            $guesty->commission,
            $this->stripeSecretEncrypted,
            $guesty->isDormant() ? ($this->archivedAt ?? $now) : null,
            $guesty->active,
        );
    }

    public function archived(DateTimeImmutable $now): self
    {
        return $this->withArchivedAt($this->archivedAt ?? $now);
    }

    /** Saisies locales : un champ nul reste inchangé, une chaîne vide efface la valeur. */
    public function withLocalFields(?string $companyName, ?string $siret): self
    {
        return new self($this->id, $this->guestyId, $this->name, $this->firstName, $this->lastName, $this->email, $this->phone, $this->address, $this->type, $companyName ?? $this->companyName, $siret ?? $this->siret, $this->commission, $this->stripeSecretEncrypted, $this->archivedAt, $this->guestyActive);
    }

    public function withStripeSecret(?string $encrypted): self
    {
        return new self($this->id, $this->guestyId, $this->name, $this->firstName, $this->lastName, $this->email, $this->phone, $this->address, $this->type, $this->companyName, $this->siret, $this->commission, $encrypted, $this->archivedAt, $this->guestyActive);
    }

    public function hasStripeKey(): bool
    {
        return $this->stripeSecretEncrypted !== null && $this->stripeSecretEncrypted !== '';
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Vrai si les deux propriétaires ne diffèrent que par la date d'archivage exacte (archivé des deux côtés). */
    public function sameAs(self $other): bool
    {
        return $this->isArchived() === $other->isArchived()
            && $this->withArchivedAt(null) == $other->withArchivedAt(null);
    }

    private function withArchivedAt(?DateTimeImmutable $archivedAt): self
    {
        return new self($this->id, $this->guestyId, $this->name, $this->firstName, $this->lastName, $this->email, $this->phone, $this->address, $this->type, $this->companyName, $this->siret, $this->commission, $this->stripeSecretEncrypted, $archivedAt, $this->guestyActive);
    }
}
