<?php

declare(strict_types=1);

namespace Casamiza\Tests\Invoice;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Statement\Statement;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** Émission et lecture des factures : `/invoices` (administrateurs seulement). */
final class InvoiceTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '1 rue du Port, Ajaccio', OwnerType::Company, 'SCI Les Pins', '12345678900012', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '', 48.0, 17.0, null));
        $this->properties->add(new Property('prop-2', 'L2', 'owner-2', 'Studio Port', '', 30.0, 0.0, null));
        // Septembre 2026 : deux séjours facturables ; un séjour propriétaire, un séjour archivé, un séjour d'octobre et celui d'un autre propriétaire.
        $this->reservation('res-1', 'owner-1', 'prop-1', 'airbnb2', '2026-09-02', '2026-09-05', 27.86);
        $this->reservation('res-2', 'owner-1', 'prop-1', 'Booking.com', '2026-09-10', '2026-09-14', 77.72);
        $this->reservation('res-owner', 'owner-1', 'prop-1', 'owner', '2026-09-20', '2026-09-22', 0.0);
        $this->reservation('res-old', 'owner-1', 'prop-1', 'airbnb2', '2026-09-03', '2026-09-06', 999.0, archived: true);
        $this->reservation('res-oct', 'owner-1', 'prop-1', 'airbnb2', '2026-10-01', '2026-10-04', 40.0);
        $this->reservation('res-other', 'owner-2', 'prop-2', 'airbnb2', '2026-09-08', '2026-09-12', 50.0);
        $this->extras->add(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Linen, 'Linge le 19/09', 17.0, 20.0));
        $this->extras->add(Extra::priced('extra-2', 'owner-1', '2026-09', ExtraType::Cleaning, 'Ménage le 19/09', 48.0, 20.0));
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre Propriétaire', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Chalet', '', 10.0, 0.0, null));
        $this->reservation('res-b', 'owner-b', 'prop-b', 'airbnb2', '2026-09-08', '2026-09-12', 100.0);
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $ownerId, string $propertyId, string $source, string $checkIn, string $checkOut, float $conciergeFee, bool $archived = false): void
    {
        $reservation = Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            $source,
            [],
            '',
            'Voyageur ' . $id,
            new DateTimeImmutable($checkIn . ' 14:00:00 UTC'),
            new DateTimeImmutable($checkOut . ' 08:00:00 UTC'),
            (int) (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days,
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
        ), $ownerId, $propertyId)->withCalculation(new CalculatedAmounts(500.0, 50.0, 5.0, 12.0, 48.0, 433.0, $conciergeFee, 300.0, []));
        $this->reservations->add($archived ? $reservation->archived(new DateTimeImmutable('2026-10-01 UTC')) : $reservation);
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, '/api/v1' . $path, $this->bearer($this->accessToken($email)), $body);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, mixed> */
    private function issue(string $ownerId = 'owner-1', string $month = '2026-09', string $email = 'alice@a.test'): array
    {
        return $this->data($this->call('POST', '/invoices', ['ownerId' => $ownerId, 'month' => $month], $email), 201);
    }

    public function testNeedsAnAuthenticatedAdministrator(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/invoices')->getStatusCode());
        foreach ([['GET', '/invoices'], ['POST', '/invoices'], ['GET', '/invoices/x/json'], ['PATCH', '/invoices/x/payment']] as [$method, $path]) {
            $response = $this->call($method, $path, in_array($method, ['POST', 'PATCH'], true) ? ['ownerId' => 'owner-1', 'month' => '2026-09'] : null, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertSame([], $this->invoices->rows);
    }

    public function testAnInvoiceIsComputedFromTheStoredReservationsAndTheExtrasOfTheMonth(): void
    {
        $invoice = $this->issue();

        self::assertSame(1, $invoice['number']);
        self::assertSame('FZ0000001', $invoice['displayNumber']);
        self::assertSame(['owner-1', '2026-09', '2026-09-01', '2026-09-30'], [$invoice['ownerId'], $invoice['month'], $invoice['periodStart'], $invoice['periodEnd']]);
        self::assertSame(['2026-10-07', '2026-11-07'], [$invoice['invoiceDate'], $invoice['dueDate']]);
        self::assertSame([250.48, 50.1, 300.58], [$invoice['amountHt'], $invoice['vat'], $invoice['amountTtc']]);
        self::assertSame(['unpaid', 'casamiza'], [$invoice['status'], $invoice['source']]);

        $this->context->reset();
        $this->context->set('tenant-a');
        $key = 'tenant-a|' . $invoice['id'];
        // 2 réservations facturables × (conciergerie, linge, ménage) + 2 prestations ; ni séjour propriétaire, ni archivé, ni d'octobre, ni d'un autre propriétaire.
        self::assertCount(8, $this->invoices->lines[$key]);
        self::assertSame(['res-1', 'res-2'], array_map(static fn($r): ?string => $r->reservationId, $this->invoices->reservations[$key]));
        $this->context->reset();
    }

    public function testTheSnapshotKeepsFacturizasStructureWithTheBilledAmounts(): void
    {
        $invoice = $this->issue();
        $snapshot = $this->data($this->call('GET', '/invoices/' . $invoice['id'] . '/json'));

        self::assertSame(['facture_information', 'facturation_par_listing', 'facturation_supplementaire', 'totaux_par_categorie', 'facture_totaux'], array_keys($snapshot));
        /** @var array<string, mixed> $information */
        $information = $snapshot['facture_information'];
        self::assertSame(['FZ0000001', 'EUR', '2026-10-07', '2026-09-01', '2026-09-30'], [$information['numero_facture'], $information['devise'], $information['date_facture'], $information['periode_start'], $information['periode_end']]);
        self::assertSame(['client_name' => 'Alice Martin', 'client_siret' => '12345678900012', 'client_legal_name' => 'SCI Les Pins', 'client_address' => '1 rue du Port, Ajaccio'], $information['client']);

        /** @var list<array<string, mixed>> $listings */
        $listings = $snapshot['facturation_par_listing'];
        self::assertCount(1, $listings);
        self::assertSame(['Villa Santa', 'L1'], [$listings[0]['listing_nickname'], $listings[0]['listing_guesty_id']]);
        /** @var list<array<string, mixed>> $reservations */
        $reservations = $listings[0]['listing_reservations'];
        self::assertCount(2, $reservations);
        /** @var array<string, mixed> $details */
        $details = $reservations[0]['reservation_details'];
        self::assertSame(['G-res-1', 'L1', 'AirBnB', 27.86], [$details['guesty_res_id'], $details['guesty_listing_id'], $details['channel'], $details['conciergerie_fee']]);
        // Les nombres entiers reviennent du JSON en entiers : on compare les valeurs, pas les types.
        self::assertEquals(['frais_conciergerie' => ['Montant_HT' => 23.22, 'TVA' => 20.0, 'Montant_TTC' => 27.86], 'frais_linge' => ['Montant_HT' => 14.17, 'TVA' => 20.0, 'Montant_TTC' => 17.0], 'frais_menage' => ['Montant_HT' => 40.0, 'TVA' => 20.0, 'Montant_TTC' => 48.0], 'frais_comission_plateforme' => []], $reservations[0]['facturation_details']);

        self::assertEquals(
            ['conciergerie' => [], 'linge' => [['description' => 'Linge le 19/09', 'Montant_HT' => 14.16, 'TVA' => 20.0, 'Montant_TTC' => 17.0]], 'menage' => [['description' => 'Ménage le 19/09', 'Montant_HT' => 40.0, 'TVA' => 20.0, 'Montant_TTC' => 48.0]]],
            $snapshot['facturation_supplementaire'],
        );
        self::assertEquals(
            ['total_conciergerie' => ['Montant_HT' => 87.99, 'TVA' => 20.0, 'Montant_TTC' => 105.58], 'total_linge' => ['Montant_HT' => 42.49, 'TVA' => 20.0, 'Montant_TTC' => 51.0], 'total_menage' => ['Montant_HT' => 120.0, 'TVA' => 20.0, 'Montant_TTC' => 144.0]],
            $snapshot['totaux_par_categorie'],
        );
        self::assertEquals(['total_ht' => 250.48, 'total_tva' => 50.1, 'total_ttc' => 300.58], $snapshot['facture_totaux']);
    }

    public function testNumbersFollowEachOtherPerTenantWithoutGap(): void
    {
        self::assertSame(1, $this->issue()['number']);
        self::assertSame(2, $this->issue('owner-2')['number']);
        self::assertSame(1, $this->issue('owner-b', '2026-09', 'bob@b.test')['number']);
    }

    public function testAMonthIsInvoicedOnlyOnceAndThenFreezesItsExtras(): void
    {
        $this->issue();

        $again = $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09']);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('month_already_invoiced', $this->errorCode($again));

        $extra = $this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'linen', 'description' => 'Trop tard', 'priceTtc' => 10]);
        self::assertSame(409, $extra->getStatusCode());
        self::assertSame('month_already_invoiced', $this->errorCode($extra));
    }

    public function testACancelledInvoiceDoesNotBlockANewOne(): void
    {
        $invoice = $this->issue();
        $this->context->reset();
        $this->context->set('tenant-a');
        $key = 'tenant-a|' . $invoice['id'];
        $stored = $this->invoices->rows[$key];
        $this->invoices->rows[$key] = new Invoice(...[...get_object_vars($stored), 'cancelledAt' => new DateTimeImmutable('2026-10-07 UTC')]);
        $this->context->reset();

        $second = $this->issue();
        self::assertSame(2, $second['number']);
        self::assertSame(['cancelled', 'unpaid'], array_map(static fn(array $i): string => (string) $i['status'], array_reverse($this->listing())));
    }

    public function testOnlyFinishedBillableMonthsAreAccepted(): void
    {
        foreach (['2026-10' => 'the current month', '2026-11' => 'the future', '2024-12' => 'before January 2025', 'sept-26' => 'not a month'] as $month => $why) {
            $response = $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => (string) $month]);
            self::assertSame(422, $response->getStatusCode(), $why);
            self::assertSame('month_not_billable', $this->errorCode($response), $why);
        }
        self::assertSame([], $this->invoices->rows);
    }

    public function testRefusesAnUnknownOwnerAnOwnerOfAnotherTenantAndAnEmptyMonth(): void
    {
        foreach (['nope', 'owner-b'] as $ownerId) {
            $response = $this->call('POST', '/invoices', ['ownerId' => $ownerId, 'month' => '2026-09']);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('owner_not_found', $this->errorCode($response));
        }

        $empty = $this->call('POST', '/invoices', ['ownerId' => 'owner-2', 'month' => '2026-08']);
        self::assertSame(422, $empty->getStatusCode());
        self::assertSame('nothing_to_bill', $this->errorCode($empty));
        self::assertSame([], $this->invoices->rows);
    }

    public function testAMonthMarkedNothingToInvoiceIsRefused(): void
    {
        $this->context->set('tenant-a');
        $this->statements->save(new Statement('statement-1', 'owner-1', '2026-09', true));
        $this->context->reset();

        $response = $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-09']);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('month_marked_nothing_to_invoice', $this->errorCode($response));
    }

    public function testInputIsValidated(): void
    {
        $response = $this->call('POST', '/invoices', ['month' => '2026-09', 'note' => str_repeat('x', 501), 'amount' => 1]);

        self::assertSame(422, $response->getStatusCode());
        $fields = $this->json($response)['error']['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertSame(['required'], $fields['ownerId']);
        self::assertSame(['too_long'], $fields['note']);
        self::assertSame(['unknown_field'], $fields['amount']);
    }

    public function testTheListIsScopedToTheTenantAndFlagsLateInvoices(): void
    {
        $this->issue();
        $this->issue('owner-b', '2026-09', 'bob@b.test');

        $mine = $this->listing();
        self::assertCount(1, $mine);
        self::assertSame('unpaid', $mine[0]['status']);

        $this->clock->advance('+2 months');
        self::assertSame('overdue', $this->listing()[0]['status']);
        self::assertCount(1, $this->listing('bob@b.test'));
    }

    public function testAnInvoiceOfAnotherTenantOrWithoutSnapshotIsNotReadable(): void
    {
        $invoice = $this->issue();
        $other = $this->call('GET', '/invoices/' . $invoice['id'] . '/json', null, 'bob@b.test');
        self::assertSame(404, $other->getStatusCode());
        self::assertSame('invoice_not_found', $this->errorCode($other));

        $this->invoiced->invoice('tenant-a', 'owner-2', '2026-08');
        $bare = $this->call('GET', '/invoices/invoice-owner-2-2026-08/json');
        self::assertSame(404, $bare->getStatusCode());
        self::assertSame('snapshot_not_found', $this->errorCode($bare));
    }

    public function testAnInvoiceIsMarkedPaidWithItsMethodAndDayThenCanBeUnpaid(): void
    {
        $invoice = $this->issue();
        $path = '/invoices/' . $invoice['id'] . '/payment';

        // Sans jour : aujourd'hui (7 octobre 2026), le jour de la facture.
        $paid = $this->data($this->call('PATCH', $path, ['status' => 'paid', 'method' => 'wire']));
        self::assertSame(['paid', 'wire', '2026-10-07'], [$paid['status'], $paid['paymentMethod'], $paid['paymentDate']]);

        // Une facture payée n'est jamais en retard, même longtemps après son échéance.
        $this->clock->advance('+3 months');
        self::assertSame('paid', $this->listing()[0]['status']);

        $unpaid = $this->data($this->call('PATCH', $path, ['status' => 'unpaid']));
        self::assertSame(['overdue', null, null], [$unpaid['status'], $unpaid['paymentMethod'], $unpaid['paymentDate']]);

        $this->clock->advance('-3 months');
        $again = $this->data($this->call('PATCH', $path, ['status' => 'paid', 'method' => 'credit_card', 'date' => '2026-10-07']));
        self::assertSame(['credit_card', '2026-10-07'], [$again['paymentMethod'], $again['paymentDate']]);
    }

    public function testThePaymentIsValidated(): void
    {
        $path = '/invoices/' . $this->issue()['id'] . '/payment';
        $cases = [
            'no status' => [[], 'status', 'required'],
            'unknown status' => [['status' => 'late'], 'status', 'invalid_value'],
            'paid without a method' => [['status' => 'paid'], 'method', 'required'],
            'unknown method' => [['status' => 'paid', 'method' => 'cheque'], 'method', 'invalid_value'],
            'day before the invoice' => [['status' => 'paid', 'method' => 'wire', 'date' => '2026-10-06'], 'date', 'out_of_range'],
            'day in the future' => [['status' => 'paid', 'method' => 'wire', 'date' => '2026-10-08'], 'date', 'out_of_range'],
            'not a day' => [['status' => 'paid', 'method' => 'wire', 'date' => '2026-02-31'], 'date', 'invalid_date'],
            'method on an unpaid invoice' => [['status' => 'unpaid', 'method' => 'wire'], 'method', 'not_allowed'],
            'day on an unpaid invoice' => [['status' => 'unpaid', 'date' => '2026-10-07'], 'date', 'not_allowed'],
            'unknown field' => [['status' => 'paid', 'method' => 'wire', 'amount' => 1], 'amount', 'unknown_field'],
        ];
        foreach ($cases as $name => [$body, $field, $code]) {
            $response = $this->call('PATCH', $path, $body);
            self::assertSame(422, $response->getStatusCode(), $name);
            $fields = $this->json($response)['error']['fields'] ?? null;
            self::assertIsArray($fields, $name);
            self::assertSame([$code], $fields[$field] ?? null, $name);
        }
        self::assertSame('unpaid', $this->listing()[0]['status'], 'rien n\'a été enregistré');
    }

    public function testACancelledOrForeignInvoiceCannotBePaid(): void
    {
        $invoice = $this->issue();
        $path = '/invoices/' . $invoice['id'] . '/payment';
        $body = ['status' => 'paid', 'method' => 'wire'];

        $foreign = $this->call('PATCH', $path, $body, 'bob@b.test');
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('invoice_not_found', $this->errorCode($foreign));

        $this->context->reset();
        $this->context->set('tenant-a');
        $key = 'tenant-a|' . $invoice['id'];
        $this->invoices->rows[$key] = new Invoice(...[...get_object_vars($this->invoices->rows[$key]), 'cancelledAt' => new DateTimeImmutable('2026-10-07 UTC')]);
        $this->context->reset();

        $cancelled = $this->call('PATCH', $path, $body);
        self::assertSame(409, $cancelled->getStatusCode());
        self::assertSame('invoice_cancelled', $this->errorCode($cancelled));
    }

    /** @return list<array<string, mixed>> */
    private function listing(string $email = 'alice@a.test'): array
    {
        $data = $this->json($this->call('GET', '/invoices', null, $email))['data'];
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }
}
