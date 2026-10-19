<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Reservation;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** `PATCH /properties/{id}` : un administrateur affecte un logement à un propriétaire de Casamiza. */
final class PropertyAssignmentTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        // Deux entités à facturer ; Guesty rattache les deux logements au premier (portail unique).
        $this->owners->add(new Owner('owner-d', 'GD', 'Dauphiné', 'Jacques', 'Boujeant', '', '', '', OwnerType::Company, '', '', 10.0, null, null));
        $this->owners->add(new Owner('owner-t', 'GT', 'Trianon', 'Jacques', 'Boujeant', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-d', 'LD', 'owner-d', 'Dauphiné', '', 48.0, 17.0, null));
        $this->properties->add(new Property('prop-t', 'LT', 'owner-d', 'Trianon', '', 66.0, 17.0, null));
        $this->addReservation('res-d', 'owner-d', 'prop-d');
        $this->addReservation('res-t1', 'owner-d', 'prop-t');
        $this->addReservation('res-t2', 'owner-d', 'prop-t');
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GB', 'Autre', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Chalet', '', 0.0, 0.0, null));
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function addReservation(string $id, string $ownerId, string $propertyId): void
    {
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            'airbnb2',
            [],
            '',
            'Jean Dupont',
            new DateTimeImmutable('2026-09-02 14:00:00 UTC'),
            new DateTimeImmutable('2026-09-05 08:00:00 UTC'),
            3,
            2,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            [],
            [],
        ), $ownerId, $propertyId));
    }

    /** @param array<string, mixed>|null $json */
    private function patch(string $propertyId, ?array $json, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'PATCH', '/api/v1/properties/' . $propertyId, $this->bearer($this->accessToken($email)), $json);
    }

    private function ownerOfReservation(string $id): ?string
    {
        return $this->reservations->rows['tenant-a|' . $id]->ownerId;
    }

    public function testAnAdminMovesAPropertyAndItsWholeHistoryFollows(): void
    {
        $response = $this->patch('prop-t', ['ownerId' => 'owner-t']);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertSame('owner-t', $data['ownerId']);
        self::assertSame('Trianon', $data['ownerName']);
        self::assertTrue($data['ownerManual']);
        self::assertSame(2, $data['movedReservations']);
        self::assertSame('owner-t', $this->properties->rows['tenant-a|prop-t']->ownerId);
        self::assertSame('owner-t', $this->ownerOfReservation('res-t1'));
        self::assertSame('owner-t', $this->ownerOfReservation('res-t2'));
        // L'autre logement du premier propriétaire ne bouge pas.
        self::assertSame('owner-d', $this->properties->rows['tenant-a|prop-d']->ownerId);
        self::assertSame('owner-d', $this->ownerOfReservation('res-d'));
    }

    public function testAPropertyCanBeLeftWithoutOwner(): void
    {
        $response = $this->patch('prop-t', ['ownerId' => null]);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertNull($data['ownerId']);
        self::assertNull($data['ownerName']);
        self::assertNull($this->ownerOfReservation('res-t1'));
        self::assertTrue($this->properties->rows['tenant-a|prop-t']->ownerManual);
    }

    public function testTheChangeIsAuditedWithoutBeingRepeatedWhenNothingChanges(): void
    {
        $this->patch('prop-t', ['ownerId' => 'owner-t']);
        $this->patch('prop-t', ['ownerId' => 'owner-t']);

        $entries = $this->audit->of(AuditAction::PropertyOwnerChanged);
        self::assertCount(1, $entries);
        self::assertSame('property', $entries[0]->targetType);
        self::assertSame('Trianon', $entries[0]->targetLabel);
        self::assertSame(['from' => 'Dauphiné', 'to' => 'Trianon', 'reservations' => 2], $entries[0]->context);
    }

    public function testManagersCannotAssign(): void
    {
        $response = $this->patch('prop-t', ['ownerId' => 'owner-t'], 'marc@a.test');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
        self::assertSame('owner-d', $this->properties->rows['tenant-a|prop-t']->ownerId);
        self::assertFalse($this->properties->rows['tenant-a|prop-t']->ownerManual);
    }

    public function testTheOwnerIdIsRequiredAndOtherFieldsAreRejected(): void
    {
        self::assertSame(422, $this->patch('prop-t', [])->getStatusCode());
        self::assertSame(422, $this->patch('prop-t', ['ownerId' => 'owner-t', 'name' => 'Pirate'])->getStatusCode());
        self::assertSame(422, $this->patch('prop-t', ['ownerId' => 12])->getStatusCode());
        self::assertSame('Trianon', $this->properties->rows['tenant-a|prop-t']->name);
    }

    public function testUnknownPropertyOrOwnerIsNotFound(): void
    {
        $missingProperty = $this->patch('nope', ['ownerId' => 'owner-t']);
        self::assertSame(404, $missingProperty->getStatusCode());
        self::assertSame('property_not_found', $this->errorCode($missingProperty));

        $missingOwner = $this->patch('prop-t', ['ownerId' => 'nope']);
        self::assertSame(404, $missingOwner->getStatusCode());
        self::assertSame('owner_not_found', $this->errorCode($missingOwner));
    }

    public function testTenantsAreIsolated(): void
    {
        // Un logement ou un propriétaire de B : 404 pour A, et rien ne bouge chez B.
        $otherProperty = $this->patch('prop-b', ['ownerId' => 'owner-t']);
        self::assertSame(404, $otherProperty->getStatusCode());
        self::assertSame('property_not_found', $this->errorCode($otherProperty));

        $otherOwner = $this->patch('prop-t', ['ownerId' => 'owner-b']);
        self::assertSame(404, $otherOwner->getStatusCode());
        self::assertSame('owner_not_found', $this->errorCode($otherOwner));

        self::assertSame('owner-d', $this->properties->rows['tenant-a|prop-t']->ownerId);
        self::assertSame('owner-b', $this->properties->rows['tenant-b|prop-b']->ownerId);
        self::assertFalse($this->properties->rows['tenant-b|prop-b']->ownerManual);
    }
}
