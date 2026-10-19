<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Transaction;

/** Propriétaires du tenant courant : lecture (alimentée par la synchronisation Guesty) et saisies locales. */
final readonly class OwnerService
{
    public function __construct(
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private Encryptor $encryptor,
        private Transaction $transaction,
        private AuditLogInterface $audit,
    ) {}

    /**
     * Tous les propriétaires, inactifs (archivés) compris, chacun avec **tous** ses logements, inactifs compris : le logement
     * d'un propriétaire désactivé est lui-même inactif, et la liste ne doit pas paraître vide.
     *
     * @return list<array{owner: Owner, properties: list<Property>}>
     */
    public function list(): array
    {
        $byOwner = $this->propertiesByOwner();

        $rows = [];
        foreach ($this->owners->all() as $owner) {
            $rows[] = ['owner' => $owner, 'properties' => $byOwner[$owner->id] ?? []];
        }

        return $rows;
    }

    /**
     * Modifie les champs saisis dans Casamiza. `null` = inchangé ; une chaîne vide efface raison sociale et SIRET.
     * La clé Stripe est chiffrée avant d'être écrite et n'est plus jamais relue en clair.
     *
     * @return array{owner: Owner, properties: list<Property>}
     * @throws NotFoundException `owner_not_found`
     */
    public function update(string $id, ?string $companyName, ?string $siret, ?string $stripeKey): array
    {
        $owner = $this->owners->find($id) ?? throw new NotFoundException('owner_not_found');
        $before = $owner;
        $owner = $owner->withLocalFields($companyName, $siret);
        if ($stripeKey !== null) {
            $owner = $owner->withStripeSecret($this->encryptor->encrypt($stripeKey));
        }
        $this->transaction->run(function () use ($before, $owner, $stripeKey): void {
            $this->owners->updateLocal($owner);
            // Jamais la clé ni le SIRET : seulement ce qui a changé.
            if ($stripeKey !== null) {
                $this->audit->record(AuditAction::OwnerStripeKeySet, 'owner', $owner->id, $owner->name, ['replaced' => $before->stripeSecretEncrypted !== null]);
            }
        });

        return $this->row($owner);
    }

    /**
     * @return array{owner: Owner, properties: list<Property>}
     * @throws NotFoundException `owner_not_found`
     */
    public function removeStripeKey(string $id): array
    {
        $owner = ($this->owners->find($id) ?? throw new NotFoundException('owner_not_found'))->withStripeSecret(null);
        $this->transaction->run(function () use ($owner): void {
            $this->owners->updateLocal($owner);
            $this->audit->record(AuditAction::OwnerStripeKeyRemoved, 'owner', $owner->id, $owner->name);
        });

        return $this->row($owner);
    }

    /**
     * @return array{owner: Owner, properties: list<Property>}
     */
    private function row(Owner $owner): array
    {
        return ['owner' => $owner, 'properties' => $this->propertiesByOwner()[$owner->id] ?? []];
    }

    /** @return array<string, list<Property>> logements (archivés compris) par propriétaire */
    private function propertiesByOwner(): array
    {
        $byOwner = [];
        foreach ($this->properties->all() as $property) {
            if ($property->ownerId !== null) {
                $byOwner[$property->ownerId][] = $property;
            }
        }

        return $byOwner;
    }
}
