<?php

declare(strict_types=1);

namespace Casamiza\Domain\Property;

use Casamiza\Domain\Guesty\GuestyListing;
use DateTimeImmutable;

/**
 * Logement. Tout vient de Guesty et est réécrit à chaque synchronisation : nom, adresse, propriétaire, et coûts de
 * ménage et de linge (champs personnalisés `cout_ménage` et `cout_linge`).
 */
final readonly class Property
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public string $guestyId,
        public ?string $ownerId,
        public string $name,
        public string $address,
        public float $cleaningFee,
        public float $linenFee,
        public ?DateTimeImmutable $archivedAt,
    ) {}

    /** @param non-empty-string $id */
    public static function fromGuesty(string $id, GuestyListing $guesty, ?string $ownerId, DateTimeImmutable $now): self
    {
        return new self($id, $guesty->guestyId, $ownerId, $guesty->name, $guesty->address, $guesty->cleaningFee, $guesty->linenFee, $guesty->active ? null : $now);
    }

    public function mergedWith(GuestyListing $guesty, ?string $ownerId, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->guestyId,
            $ownerId,
            $guesty->name,
            $guesty->address,
            $guesty->cleaningFee,
            $guesty->linenFee,
            $guesty->active ? null : ($this->archivedAt ?? $now),
        );
    }

    public function archived(DateTimeImmutable $now): self
    {
        return new self($this->id, $this->guestyId, $this->ownerId, $this->name, $this->address, $this->cleaningFee, $this->linenFee, $this->archivedAt ?? $now);
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Vrai si les deux logements ne diffèrent que par la date d'archivage exacte (archivé des deux côtés). */
    public function sameAs(self $other): bool
    {
        return $this->isArchived() === $other->isArchived()
            && $this->withoutArchiveDate() == $other->withoutArchiveDate();
    }

    private function withoutArchiveDate(): self
    {
        return new self($this->id, $this->guestyId, $this->ownerId, $this->name, $this->address, $this->cleaningFee, $this->linenFee, null);
    }
}
