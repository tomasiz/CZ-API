<?php

declare(strict_types=1);

namespace Casamiza\Tests\Invoice;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/**
 * Un logement réaffecté à un autre propriétaire (PropertyService::assignOwner déplace aussi ses anciens séjours) ne doit
 * jamais refacturer un séjour déjà porté par une facture non annulée.
 */
final class ReassignedPropertyBillingTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '', 48.0, 17.0, null));
        $this->addStay('res-1', 'owner-1', '2026-09-02', '2026-09-05');
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function addStay(string $id, string $ownerId, string $checkIn, string $checkOut): void
    {
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            'airbnb2',
            [],
            '',
            'Voyageur',
            new DateTimeImmutable($checkIn . ' 14:00:00 UTC'),
            new DateTimeImmutable($checkOut . ' 08:00:00 UTC'),
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
        ), $ownerId, 'prop-1')->withCalculation(new CalculatedAmounts(500.0, 50.0, 5.0, 12.0, 48.0, 433.0, 27.86, 300.0, [])));
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->request($this->app, $method, '/v1' . $path, $this->bearer($this->accessToken('alice@a.test')), $body);
    }

    /** @return list<string> identifiants des réservations portées par les factures non annulées, un par ligne d'activité */
    private function invoicedReservationIds(): array
    {
        $ids = [];
        foreach ($this->invoices->reservations as $key => $activity) {
            if (!$this->invoices->rows[$key]->isCancelled()) {
                foreach ($activity as $row) {
                    $ids[] = (string) $row->reservationId;
                }
            }
        }
        sort($ids);

        return $ids;
    }

    public function testAnInvoicedStayIsNotBilledAgainToTheNewOwnerAfterAReassignment(): void
    {
        self::assertSame(201, $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09'])->getStatusCode());
        self::assertSame(200, $this->call('PATCH', '/properties/prop-1', ['ownerId' => 'owner-2'])->getStatusCode());

        // Le séjour est maintenant chez owner-2 mais figure déjà sur la facture d'owner-1 : rien à facturer.
        $second = $this->call('POST', '/invoices', ['ownerId' => 'owner-2', 'month' => '2026-09']);
        self::assertSame(422, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame('nothing_to_bill', $this->json($second)['error']['code'] ?? null);
        self::assertSame(['res-1'], $this->invoicedReservationIds(), 'la réservation n\'est portée que par une seule facture');

        // L'aperçu applique la même règle : il n'annonce pas une facture qui serait refusée.
        self::assertSame(422, $this->call('POST', '/invoices/preview', ['ownerId' => 'owner-2', 'month' => '2026-09'])->getStatusCode());
    }

    public function testOnlyTheStaysNotYetInvoicedAreBilledToTheNewOwner(): void
    {
        self::assertSame(201, $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09'])->getStatusCode());
        // Un séjour du même mois arrive de Guesty après la facture d'owner-1, puis le logement est réaffecté.
        $this->context->reset();
        $this->context->set('tenant-a');
        $this->addStay('res-late', 'owner-1', '2026-09-20', '2026-09-23');
        $this->context->reset();
        self::assertSame(200, $this->call('PATCH', '/properties/prop-1', ['ownerId' => 'owner-2'])->getStatusCode());

        $second = $this->call('POST', '/invoices', ['ownerId' => 'owner-2', 'month' => '2026-09']);
        self::assertSame(201, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame(['res-1', 'res-late'], $this->invoicedReservationIds(), 'chaque séjour sur une seule facture : res-1 chez owner-1, res-late chez owner-2');
    }

    public function testAMonthAlreadyInvoicedForTheNewOwnerStillAnswersConflict(): void
    {
        self::assertSame(201, $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09'])->getStatusCode());
        $this->context->reset();
        $this->context->set('tenant-a');
        $this->properties->add(new Property('prop-2', 'L2', 'owner-2', 'Studio', '', 0.0, 0.0, null));
        $this->reservations->add(Reservation::fromGuesty('res-2', new GuestyReservation('G-res-2', 'L', 'airbnb2', [], '', 'Voyageur', new DateTimeImmutable('2026-09-10 14:00:00 UTC'), new DateTimeImmutable('2026-09-14 08:00:00 UTC'), 4, 2, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, [], []), 'owner-2', 'prop-2')
            ->withCalculation(new CalculatedAmounts(500.0, 50.0, 5.0, 12.0, 48.0, 433.0, 30.0, 300.0, [])));
        $this->context->reset();
        self::assertSame(201, $this->call('POST', '/invoices', ['ownerId' => 'owner-2', 'month' => '2026-09'])->getStatusCode());
        self::assertSame(200, $this->call('PATCH', '/properties/prop-1', ['ownerId' => 'owner-2'])->getStatusCode());

        self::assertSame(409, $this->call('POST', '/invoices', ['ownerId' => 'owner-2', 'month' => '2026-09'])->getStatusCode());
    }
}
