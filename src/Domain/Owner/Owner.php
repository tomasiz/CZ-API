<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

use Casamiza\Domain\Guesty\GuestyOwner;
use DateTimeImmutable;

/**
 * Propriétaire. Les champs de Guesty (nom, e-mail, téléphone, adresse, type, commission) sont réécrits à chaque
 * synchronisation ; `companyName` et `siret` sont locaux et n'y sont jamais touchés.
 */
final readonly class Owner
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public string $guestyId,
        public string $name,
        public string $email,
        public string $phone,
        public string $address,
        public OwnerType $type,
        public string $companyName,
        public string $siret,
        public ?float $commission,
        public ?DateTimeImmutable $archivedAt,
    ) {}

    /** @param non-empty-string $id */
    public static function fromGuesty(string $id, GuestyOwner $guesty, DateTimeImmutable $now): self
    {
        return new self($id, $guesty->guestyId, $guesty->name, $guesty->email, $guesty->phone, $guesty->address, $guesty->type, '', '', $guesty->commission, $guesty->active ? null : $now);
    }

    /** Applique l'état de Guesty ; un propriétaire déjà archivé garde sa date d'archivage tant qu'il reste inactif. */
    public function mergedWith(GuestyOwner $guesty, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->guestyId,
            $guesty->name,
            $guesty->email,
            $guesty->phone,
            $guesty->address,
            $guesty->type,
            $this->companyName,
            $this->siret,
            $guesty->commission,
            $guesty->active ? null : ($this->archivedAt ?? $now),
        );
    }

    public function archived(DateTimeImmutable $now): self
    {
        return new self($this->id, $this->guestyId, $this->name, $this->email, $this->phone, $this->address, $this->type, $this->companyName, $this->siret, $this->commission, $this->archivedAt ?? $now);
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Vrai si les deux propriétaires ne diffèrent que par la date d'archivage exacte (archivé des deux côtés). */
    public function sameAs(self $other): bool
    {
        return $this->isArchived() === $other->isArchived()
            && $this->withoutArchiveDate() == $other->withoutArchiveDate();
    }

    private function withoutArchiveDate(): self
    {
        return new self($this->id, $this->guestyId, $this->name, $this->email, $this->phone, $this->address, $this->type, $this->companyName, $this->siret, $this->commission, null);
    }
}
